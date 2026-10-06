import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { ValidationErrorBody } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { LaborService, SERVICE_CATEGORIES, ServiceCategory } from '../../models/labor-service';
import { LaborServicesService } from '../../services/labor-services.service';

type FieldName = 'name' | 'category' | 'description';

const MESSAGES: Record<string, string> = {
  required: 'Campo obrigatório.',
  maxlength: 'Texto muito longo.',
};

/**
 * Cadastro/edição de um serviço em diálogo. "Salvar e adicionar outro" mantém o diálogo
 * aberto (com a mesma categoria) para cadastrar vários serviços em sequência.
 */
@Component({
  selector: 'app-service-form-dialog',
  imports: [ReactiveFormsModule, Icon],
  templateUrl: './service-form-dialog.html',
  styleUrl: './service-form-dialog.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ServiceFormDialog {
  private readonly services = inject(LaborServicesService);
  private readonly toast = inject(ToastService);

  readonly open = input(false);
  /** Serviço em edição; null = novo. */
  readonly service = input<LaborService | null>(null);

  readonly saved = output<LaborService>();
  readonly closed = output<void>();

  protected readonly categories = SERVICE_CATEGORIES;
  protected readonly isEdit = computed(() => this.service() !== null);
  protected readonly saving = signal(false);
  protected readonly submitted = signal(false);
  protected readonly formError = signal<string | null>(null);

  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(120)]],
    category: ['maintenance' as ServiceCategory, Validators.required],
    description: ['', Validators.maxLength(1000)],
    is_active: [true],
  });

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly nameInput = viewChild.required<ElementRef<HTMLInputElement>>('nameInput');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.reset(this.service());
        dialog.showModal();
        setTimeout(() => this.nameInput().nativeElement.focus());
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected errorFor(field: FieldName): string | null {
    const control = this.form.controls[field];
    if (!control.errors || !(control.touched || this.submitted())) return null;
    if (control.errors['server']) return control.errors['server'];
    return MESSAGES[Object.keys(control.errors)[0]] ?? 'Valor inválido.';
  }

  protected submit(addAnother: boolean): void {
    this.submitted.set(true);
    this.formError.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    const payload = this.form.getRawValue();
    const current = this.service();
    const request = current ? this.services.update(current.id, payload) : this.services.create(payload);

    request.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: (service) => {
        this.saved.emit(service);

        if (addAnother) {
          this.toast.success(`"${service.name}" cadastrado. Continue adicionando.`);
          // Mantém a categoria: normalmente se cadastra vários serviços do mesmo grupo
          this.reset(null, payload.category);
          setTimeout(() => this.nameInput().nativeElement.focus());
          return;
        }

        this.toast.success(current ? 'Serviço atualizado.' : `"${service.name}" cadastrado.`);
        this.closed.emit();
      },
      error: (error: unknown) => this.handleError(error),
    });
  }

  /** Esc fecha (sem perder o controle do estado no componente pai). */
  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }

  private reset(service: LaborService | null, category?: ServiceCategory): void {
    this.submitted.set(false);
    this.formError.set(null);
    this.form.reset({
      name: service?.name ?? '',
      category: service?.category ?? category ?? 'maintenance',
      description: service?.description ?? '',
      is_active: service?.is_active ?? true,
    });
  }

  private handleError(error: unknown): void {
    if (error instanceof HttpErrorResponse && error.status === 422) {
      const { errors } = error.error as ValidationErrorBody;
      for (const [field, messages] of Object.entries(errors ?? {})) {
        const control = this.form.get(field);
        control?.setErrors({ server: messages[0] });
        control?.markAsTouched();
      }
      return;
    }
    this.formError.set('Não foi possível salvar o serviço. Tente novamente.');
  }
}
