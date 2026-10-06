import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { finalize, Observable } from 'rxjs';

import { Icon } from '../../../../shared/components/icon/icon';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { centsToInput, maskMoney, moneyToCents } from '../../../../shared/utils/br-format';
import { PAYMENT_METHODS, PaymentMethod } from '../../../service-orders/models/service-order';
import { localDate } from '../../dates';
import { Bill, EXPENSE_CATEGORIES, ExpenseCategory, PayablesService, SupplierRef } from '../../payables.service';

type Field = 'description' | 'supplier_id' | 'category' | 'amount_cents' | 'due_date' | 'installments' | 'paid_at' | 'payment_method' | 'document_number';

/** Lançar ou editar uma conta a pagar. No lançamento: parcelado (1 conta por mês) ou já pago. */
@Component({
  selector: 'app-bill-dialog',
  imports: [Icon, MoneyPipe],
  templateUrl: './bill-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  styles: `
    .paid-now {
      height: 44px;
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BillDialog {
  private readonly payables = inject(PayablesService);

  readonly open = input(false);
  /** null = nova conta. */
  readonly bill = input<Bill | null>(null);
  readonly suppliers = input<SupplierRef[]>([]);

  readonly saved = output<string>();
  readonly closed = output<void>();

  protected readonly categories = EXPENSE_CATEGORIES;
  protected readonly methods = PAYMENT_METHODS;

  protected readonly description = signal('');
  protected readonly supplierId = signal<number | null>(null);
  protected readonly category = signal<ExpenseCategory>('other');
  protected readonly amount = signal('');
  protected readonly dueDate = signal(localDate(new Date()));
  protected readonly documentNumber = signal('');
  protected readonly notes = signal('');
  protected readonly installments = signal(1);
  protected readonly alreadyPaid = signal(false);
  protected readonly paidAt = signal(localDate(new Date()));
  protected readonly paymentMethod = signal<PaymentMethod>('pix');

  protected readonly saving = signal(false);
  protected readonly errors = signal<Partial<Record<Field, string>>>({});
  protected readonly error = signal<string | null>(null);

  protected readonly today = localDate(new Date());
  protected readonly editing = computed(() => this.bill() !== null);
  protected readonly paid = computed(() => !!this.bill()?.paid_at);
  protected readonly amountCents = computed(() => moneyToCents(this.amount()));
  /** Valor de cada parcela (a primeira leva os centavos que sobram, como na API). */
  protected readonly installmentCents = computed(() => Math.floor(this.amountCents() / Math.max(1, this.installments())));

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.reset(this.bill());
        dialog.showModal();
        setTimeout(() => document.getElementById('bill-description')?.focus());
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected onAmount(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = maskMoney(element.value);
    this.amount.set(element.value);
  }

  protected onInstallments(value: string): void {
    const count = Math.min(48, Math.max(1, Math.trunc(Number(value) || 1)));
    this.installments.set(count);
    if (count > 1) this.alreadyPaid.set(false);
  }

  protected submit(): void {
    if (this.saving()) return;

    const errors: Partial<Record<Field, string>> = {};
    if (!this.description().trim()) errors.description = 'Descreva a conta.';
    if (this.amountCents() <= 0) errors.amount_cents = 'Informe o valor.';
    if (!this.dueDate()) errors.due_date = 'Informe o vencimento.';
    if (this.alreadyPaid() && !this.paidAt()) errors.paid_at = 'Informe a data do pagamento.';
    this.errors.set(errors);
    if (Object.keys(errors).length) return;

    const payload = {
      description: this.description().trim(),
      supplier_id: this.supplierId(),
      category: this.category(),
      amount_cents: this.amountCents(),
      due_date: this.dueDate(),
      document_number: this.documentNumber().trim(),
      notes: this.notes().trim(),
    };

    const current = this.bill();
    this.error.set(null);
    this.saving.set(true);
    const request: Observable<Bill | Bill[]> = current
      ? this.payables.updateBill(current.id, payload)
      : this.payables.createBill({
          ...payload,
          installments: this.installments(),
          paid_at: this.alreadyPaid() ? this.paidAt() : null,
          payment_method: this.alreadyPaid() ? this.paymentMethod() : null,
        });

    request.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: (result) => {
        const count = Array.isArray(result) ? result.length : 1;
        this.saved.emit(
          current ? 'Conta atualizada.' : count > 1 ? `${count} parcelas lançadas.` : this.alreadyPaid() ? 'Conta lançada como paga.' : 'Conta lançada.',
        );
      },
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 422) {
          const raw = (error.error?.errors ?? {}) as Record<string, string[]>;
          this.errors.set(Object.fromEntries(Object.entries(raw).map(([key, messages]) => [key, messages[0]])));
          if (!Object.keys(raw).length) this.error.set(error.error?.message ?? 'Confira os dados.');
          return;
        }
        this.error.set('Não foi possível salvar a conta.');
      },
    });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }

  private reset(bill: Bill | null): void {
    this.description.set(bill?.description ?? '');
    this.supplierId.set(bill?.supplier?.id ?? null);
    this.category.set(bill?.category ?? 'other');
    this.amount.set(bill ? centsToInput(bill.amount_cents) : '');
    this.dueDate.set(bill?.due_date ?? this.today);
    this.documentNumber.set(bill?.document_number ?? '');
    this.notes.set(bill?.notes ?? '');
    this.installments.set(1);
    this.alreadyPaid.set(false);
    this.paidAt.set(this.today);
    this.paymentMethod.set('pix');
    this.errors.set({});
    this.error.set(null);
  }
}
