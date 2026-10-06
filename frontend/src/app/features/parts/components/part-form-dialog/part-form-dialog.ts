import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { ValidationErrorBody } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { MaskDirective } from '../../../../shared/directives/mask.directive';
import { centsToInput, formatQuantity, moneyToCents, parseQuantity } from '../../../../shared/utils/br-format';
import { Part, PART_UNITS, PartPayload } from '../../models/part';
import { PartsService } from '../../services/parts.service';

type FieldName = 'name' | 'part_number' | 'brand' | 'unit' | 'cost' | 'price' | 'min_stock' | 'initial_stock' | 'notes';

/** Campo da API → campo do formulário (valores em centavos viram texto "12,50"). */
const SERVER_FIELDS: Record<string, FieldName> = { cost_cents: 'cost', price_cents: 'price' };

/** Cadastro/edição da peça. A quantidade só muda por entrada, ajuste ou uso na OS. */
@Component({
  selector: 'app-part-form-dialog',
  imports: [ReactiveFormsModule, Icon, MaskDirective],
  templateUrl: './part-form-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PartFormDialog {
  private readonly parts = inject(PartsService);
  private readonly toast = inject(ToastService);

  readonly open = input(false);
  readonly part = input<Part | null>(null);

  readonly saved = output<Part>();
  readonly closed = output<void>();

  protected readonly units = PART_UNITS;
  protected readonly isEdit = computed(() => this.part() !== null);
  protected readonly saving = signal(false);
  protected readonly submitted = signal(false);
  protected readonly formError = signal<string | null>(null);

  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(150)]],
    part_number: ['', Validators.maxLength(60)],
    brand: ['', Validators.maxLength(80)],
    unit: ['un', Validators.required],
    cost: [''],
    price: [''],
    min_stock: ['0'],
    initial_stock: ['0'],
    notes: ['', Validators.maxLength(1000)],
    is_active: [true],
  });

  /** Margem calculada enquanto digita. */
  protected readonly margin = signal<number | null>(null);

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly nameInput = viewChild.required<ElementRef<HTMLInputElement>>('nameInput');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.reset(this.part());
        dialog.showModal();
        setTimeout(() => this.nameInput().nativeElement.focus());
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });

    this.form.valueChanges.subscribe(({ cost, price }) => {
      const costCents = cost?.trim() ? moneyToCents(cost) : null;
      const priceCents = price?.trim() ? moneyToCents(price) : null;
      this.margin.set(costCents !== null && priceCents ? Math.round(((priceCents - costCents) / priceCents) * 1000) / 10 : null);
    });
  }

  protected errorFor(field: FieldName): string | null {
    const control = this.form.controls[field];
    if (!control.errors || !(control.touched || this.submitted())) return null;
    if (control.errors['server']) return control.errors['server'];
    return control.errors['required'] ? 'Campo obrigatório.' : 'Valor inválido.';
  }

  protected submit(): void {
    this.submitted.set(true);
    this.formError.set(null);
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const value = this.form.getRawValue();
    const payload: PartPayload = {
      name: value.name,
      part_number: value.part_number,
      brand: value.brand,
      unit: value.unit,
      cost_cents: value.cost.trim() ? moneyToCents(value.cost) : null,
      price_cents: value.price.trim() ? moneyToCents(value.price) : null,
      min_stock: parseQuantity(value.min_stock),
      is_active: value.is_active,
      notes: value.notes,
      ...(this.isEdit() ? {} : { initial_stock: parseQuantity(value.initial_stock) }),
    };

    const current = this.part();
    this.saving.set(true);
    (current ? this.parts.update(current.id, payload) : this.parts.create(payload))
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (part) => {
          this.toast.success(current ? 'Peça atualizada.' : `"${part.name}" cadastrada no estoque.`);
          this.saved.emit(part);
          this.closed.emit();
        },
        error: (error: unknown) => this.handleError(error),
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }

  private reset(part: Part | null): void {
    this.submitted.set(false);
    this.formError.set(null);
    this.form.reset({
      name: part?.name ?? '',
      part_number: part?.part_number ?? '',
      brand: part?.brand ?? '',
      unit: part?.unit ?? 'un',
      cost: part?.cost_cents != null ? centsToInput(part.cost_cents) : '',
      price: part?.price_cents != null ? centsToInput(part.price_cents) : '',
      min_stock: formatQuantity(part?.min_stock ?? 0),
      initial_stock: '0',
      notes: part?.notes ?? '',
      is_active: part?.is_active ?? true,
    });
  }

  private handleError(error: unknown): void {
    if (error instanceof HttpErrorResponse && error.status === 422) {
      const { errors } = error.error as ValidationErrorBody;
      for (const [field, messages] of Object.entries(errors ?? {})) {
        const control = this.form.get(SERVER_FIELDS[field] ?? field);
        control?.setErrors({ server: messages[0] });
        control?.markAsTouched();
      }
      return;
    }
    this.formError.set('Não foi possível salvar a peça. Tente novamente.');
  }
}
