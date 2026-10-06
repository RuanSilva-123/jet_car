import { HttpErrorResponse } from '@angular/common/http';
import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { ValidationErrorBody } from '../../core/http/api';
import { ToastService } from '../../core/services/toast.service';
import { Icon } from '../../shared/components/icon/icon';
import { MaskDirective } from '../../shared/directives/mask.directive';
import { formatBytes, formatDocument, formatPhone, formatPixKey } from '../../shared/utils/br-format';
import { BackupStatus, PixKeyType, ShopSettings, ShopSettingsResponse, ShopSettingsService } from './shop-settings.service';

type FieldName =
  | 'name'
  | 'document'
  | 'phone'
  | 'email'
  | 'address'
  | 'budget_validity_days'
  | 'warranty_text'
  | 'budget_notes'
  | 'warranty_days'
  | 'pix_key_type'
  | 'pix_key'
  | 'pix_beneficiary'
  | 'pix_city';

const PIX_PLACEHOLDERS: Record<PixKeyType, string> = {
  cnpj: '00.000.000/0000-00',
  cpf: '000.000.000-00',
  phone: '(11) 98765-4321',
  email: 'financeiro@oficina.com.br',
  random: '123e4567-e89b-12d3-a456-426614174000',
};

/** Dados da oficina que aparecem nos PDFs (orçamento e comprovante). Só o master altera. */
@Component({
  selector: 'app-shop-settings',
  imports: [ReactiveFormsModule, DatePipe, Icon, MaskDirective],
  templateUrl: './shop-settings.html',
  styleUrl: './shop-settings.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShopSettingsPage implements OnInit {
  private readonly settings = inject(ShopSettingsService);
  private readonly toast = inject(ToastService);

  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly serverErrors = signal<Partial<Record<FieldName, string>>>({});
  protected readonly pixReady = signal(false);
  protected readonly pixKeyTypes = signal<{ value: PixKeyType; label: string }[]>([]);
  protected readonly backup = signal<BackupStatus | null>(null);
  protected readonly pixKeyType = signal<PixKeyType | ''>('');
  protected readonly pixPlaceholder = computed(() => {
    const type = this.pixKeyType();
    return type ? PIX_PLACEHOLDERS[type] : 'Escolha o tipo da chave';
  });
  protected readonly formatBytes = formatBytes;

  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(120)]],
    document: [''],
    phone: [''],
    email: ['', Validators.email],
    address: ['', Validators.maxLength(300)],
    budget_validity_days: [7, [Validators.required, Validators.min(1), Validators.max(365)]],
    warranty_text: ['', Validators.maxLength(1000)],
    budget_notes: ['', Validators.maxLength(1000)],
    warranty_days: [90, [Validators.required, Validators.min(0), Validators.max(3650)]],
    pix_key_type: ['' as PixKeyType | ''],
    pix_key: ['', Validators.maxLength(77)],
    pix_beneficiary: ['', Validators.maxLength(60)],
    pix_city: ['', Validators.maxLength(60)],
  });

  constructor() {
    this.form.controls.pix_key_type.valueChanges.subscribe((type) => this.pixKeyType.set(type));
  }

  ngOnInit(): void {
    this.settings
      .get()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe((response) => this.apply(response));
    this.settings.backup().subscribe((status) => this.backup.set(status));
  }

  /** Situação do backup em uma frase + tom do aviso. */
  protected readonly backupState = computed(() => {
    const status = this.backup();
    if (!status) return null;
    if (!status.configured) {
      return { tone: 'warning', text: 'Nenhum backup encontrado. Confira se o serviço "backup" do Docker está rodando.' };
    }
    if (!status.ok) return { tone: 'danger', text: `O último backup falhou${status.error ? `: ${status.error}` : '.'}` };
    if (status.stale) return { tone: 'warning', text: 'O último backup tem mais de um dia. Confira o serviço "backup" do Docker.' };
    return { tone: 'success', text: 'Backup em dia.' };
  });

  private apply({ settings, pixReady, pixKeyTypes }: ShopSettingsResponse): void {
    this.pixReady.set(pixReady);
    this.pixKeyTypes.set(Object.entries(pixKeyTypes).map(([value, label]) => ({ value: value as PixKeyType, label })));
    this.form.reset({
      ...settings,
      document: formatDocument(settings.document),
      phone: formatPhone(settings.phone),
      pix_key: formatPixKey(settings.pix_key_type, settings.pix_key),
    });
    this.pixKeyType.set(settings.pix_key_type);
  }

  protected errorFor(field: FieldName): string | null {
    const server = this.serverErrors()[field];
    if (server) return server;
    const control = this.form.controls[field];
    if (!control.errors || !control.touched) return null;
    if (control.errors['required']) return 'Campo obrigatório.';
    if (control.errors['email']) return 'Informe um e-mail válido.';
    if (field === 'warranty_days' && (control.errors['min'] || control.errors['max'])) return 'Use um valor entre 0 e 3650 dias.';
    if (control.errors['min'] || control.errors['max']) return 'Use um valor entre 1 e 365 dias.';
    if (control.errors['maxlength']) return 'Texto muito longo.';
    return 'Valor inválido.';
  }

  protected submit(): void {
    this.serverErrors.set({});
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const value: ShopSettings = this.form.getRawValue();
    // Sem tipo de chave = Pix desligado
    if (!value.pix_key_type) value.pix_key = '';

    this.saving.set(true);
    this.settings
      .save(value)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.apply(response);
          this.toast.success('Dados da oficina salvos. Os próximos PDFs já saem atualizados.');
        },
        error: (error: unknown) => {
          if (error instanceof HttpErrorResponse && error.status === 422) {
            const { errors } = error.error as ValidationErrorBody;
            this.serverErrors.set(Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages[0]])));
            return;
          }
          this.toast.error('Não foi possível salvar.');
        },
      });
  }
}
