import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { ValidationErrorBody } from '../../core/http/api';
import { ToastService } from '../../core/services/toast.service';
import { Icon } from '../../shared/components/icon/icon';
import { MaskDirective } from '../../shared/directives/mask.directive';
import { formatDocument, formatPhone } from '../../shared/utils/br-format';
import { ShopSettingsService } from './shop-settings.service';

type FieldName = 'name' | 'document' | 'phone' | 'email' | 'address' | 'budget_validity_days' | 'warranty_text' | 'budget_notes';

/** Dados da oficina que aparecem nos PDFs (orçamento e comprovante). Só o master altera. */
@Component({
  selector: 'app-shop-settings',
  imports: [ReactiveFormsModule, Icon, MaskDirective],
  templateUrl: './shop-settings.html',
  styles: `
    :host {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 24px;
    }

    .optional {
      color: var(--color-text-muted);
      font-weight: 400;
    }

    .days {
      max-width: 220px;
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShopSettingsPage implements OnInit {
  private readonly settings = inject(ShopSettingsService);
  private readonly toast = inject(ToastService);

  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly serverErrors = signal<Partial<Record<FieldName, string>>>({});

  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(120)]],
    document: [''],
    phone: [''],
    email: ['', Validators.email],
    address: ['', Validators.maxLength(300)],
    budget_validity_days: [7, [Validators.required, Validators.min(1), Validators.max(365)]],
    warranty_text: ['', Validators.maxLength(1000)],
    budget_notes: ['', Validators.maxLength(1000)],
  });

  ngOnInit(): void {
    this.settings
      .get()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe((settings) =>
        this.form.reset({ ...settings, document: formatDocument(settings.document), phone: formatPhone(settings.phone) }),
      );
  }

  protected errorFor(field: FieldName): string | null {
    const server = this.serverErrors()[field];
    if (server) return server;
    const control = this.form.controls[field];
    if (!control.errors || !control.touched) return null;
    if (control.errors['required']) return 'Campo obrigatório.';
    if (control.errors['email']) return 'Informe um e-mail válido.';
    if (control.errors['min'] || control.errors['max']) return 'Use um valor entre 1 e 365 dias.';
    return 'Valor inválido.';
  }

  protected submit(): void {
    this.serverErrors.set({});
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    this.settings
      .save(this.form.getRawValue())
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (settings) => {
          this.form.reset({ ...settings, document: formatDocument(settings.document), phone: formatPhone(settings.phone) });
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
