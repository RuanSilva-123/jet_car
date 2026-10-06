import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, effect, ElementRef, inject, input, OnInit, output, signal, viewChild } from '@angular/core';
import { finalize } from 'rxjs';

import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { centsToInput, maskMoney, moneyToCents } from '../../../../shared/utils/br-format';
import { startOfMonth } from '../../dates';
import { EXPENSE_CATEGORIES, ExpenseCategory, PayablesService, RecurringBill, SupplierRef } from '../../payables.service';

type Field = 'description' | 'category' | 'amount_cents' | 'day_of_month' | 'starts_on' | 'ends_on' | 'supplier_id';

/**
 * Despesas fixas (aluguel, internet, contador...): todo mês viram uma conta a pagar,
 * gerada automaticamente pelo agendador, sem duplicar.
 */
@Component({
  selector: 'app-recurring-tab',
  imports: [Icon, ConfirmDialog, MoneyPipe],
  templateUrl: './recurring-tab.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss', '../../pages/bills/bills-shared.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecurringTab implements OnInit {
  private readonly payables = inject(PayablesService);
  private readonly toast = inject(ToastService);

  readonly suppliers = input<SupplierRef[]>([]);
  /** Gerou/alterou contas: a lista de contas recarrega. */
  readonly changed = output<void>();

  protected readonly categories = EXPENSE_CATEGORIES;
  protected readonly items = signal<RecurringBill[]>([]);
  protected readonly monthly = signal(0);
  protected readonly loading = signal(true);

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<RecurringBill | null>(null);
  protected readonly description = signal('');
  protected readonly category = signal<ExpenseCategory>('rent');
  protected readonly supplierId = signal<number | null>(null);
  protected readonly amount = signal('');
  protected readonly day = signal(10);
  protected readonly startsOn = signal(startOfMonth());
  protected readonly endsOn = signal('');
  protected readonly active = signal(true);
  protected readonly notes = signal('');
  protected readonly errors = signal<Partial<Record<Field, string>>>({});
  protected readonly saving = signal(false);

  protected readonly pendingDelete = signal<RecurringBill | null>(null);
  protected readonly deleting = signal(false);

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.formOpen() && !dialog.open) dialog.showModal();
      else if (!this.formOpen() && dialog.open) dialog.close();
    });
  }

  ngOnInit(): void {
    this.load();
  }

  protected create(): void {
    this.fill(null);
  }

  protected edit(item: RecurringBill): void {
    this.fill(item);
  }

  protected onAmount(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = maskMoney(element.value);
    this.amount.set(element.value);
  }

  protected save(): void {
    if (this.saving()) return;

    const errors: Partial<Record<Field, string>> = {};
    if (!this.description().trim()) errors.description = 'Descreva a despesa.';
    if (moneyToCents(this.amount()) <= 0) errors.amount_cents = 'Informe o valor.';
    if (!(this.day() >= 1 && this.day() <= 31)) errors.day_of_month = 'Dia do vencimento: de 1 a 31.';
    if (!this.startsOn()) errors.starts_on = 'Informe o início.';
    this.errors.set(errors);
    if (Object.keys(errors).length) return;

    const current = this.editing();
    this.saving.set(true);
    this.payables
      .saveRecurring(current?.id ?? null, {
        description: this.description().trim(),
        category: this.category(),
        supplier_id: this.supplierId(),
        amount_cents: moneyToCents(this.amount()),
        day_of_month: this.day(),
        starts_on: this.startsOn(),
        ends_on: this.endsOn() || null,
        is_active: this.active(),
        notes: this.notes().trim(),
      })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: () => {
          this.toast.success(current ? 'Despesa fixa atualizada.' : 'Despesa fixa cadastrada. A conta deste mês já foi lançada.');
          this.formOpen.set(false);
          this.changed.emit();
          this.load();
        },
        error: (error: unknown) => {
          if (error instanceof HttpErrorResponse && error.status === 422) {
            const raw = (error.error?.errors ?? {}) as Record<string, string[]>;
            this.errors.set(Object.fromEntries(Object.entries(raw).map(([key, messages]) => [key, messages[0]])));
            return;
          }
          this.toast.error('Não foi possível salvar a despesa fixa.');
        },
      });
  }

  protected confirmDelete(): void {
    const item = this.pendingDelete();
    if (!item) return;
    this.deleting.set(true);
    this.payables
      .deleteRecurring(item.id)
      .pipe(finalize(() => this.deleting.set(false)))
      .subscribe({
        next: () => {
          this.pendingDelete.set(null);
          this.toast.success('Despesa fixa excluída. As contas já lançadas continuam.');
          this.load();
        },
        error: () => {
          this.pendingDelete.set(null);
          this.toast.error('Não foi possível excluir.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.formOpen.set(false);
  }

  private fill(item: RecurringBill | null): void {
    this.editing.set(item);
    this.description.set(item?.description ?? '');
    this.category.set(item?.category ?? 'rent');
    this.supplierId.set(item?.supplier?.id ?? null);
    this.amount.set(item ? centsToInput(item.amount_cents) : '');
    this.day.set(item?.day_of_month ?? 10);
    this.startsOn.set(item?.starts_on ?? startOfMonth());
    this.endsOn.set(item?.ends_on ?? '');
    this.active.set(item?.is_active ?? true);
    this.notes.set(item?.notes ?? '');
    this.errors.set({});
    this.formOpen.set(true);
  }

  private load(): void {
    this.loading.set(true);
    this.payables
      .recurring()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ data, summary }) => {
          this.items.set(data);
          this.monthly.set(summary.monthly_cents);
        },
        error: () => this.toast.error('Não foi possível carregar as despesas fixas.'),
      });
  }
}
