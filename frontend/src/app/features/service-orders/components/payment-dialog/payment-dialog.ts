import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { finalize } from 'rxjs';

import { Icon } from '../../../../shared/components/icon/icon';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { centsToInput, maskMoney, moneyToCents } from '../../../../shared/utils/br-format';
import { PAYMENT_METHODS, PaymentMethod, ServiceOrder } from '../../models/service-order';
import { ServiceOrdersService } from '../../services/service-orders.service';

/** Data de hoje no fuso do navegador (YYYY-MM-DD). */
function today(): string {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

/** Registro de um recebimento da OS (total ou parcial). */
@Component({
  selector: 'app-payment-dialog',
  imports: [Icon, MoneyPipe],
  templateUrl: './payment-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  styles: `
    .methods {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: 8px;
    }

    .method {
      padding: 10px 12px;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      background: var(--color-surface);
      color: var(--color-text);
      font: inherit;
      font-weight: 600;
      text-align: left;
      cursor: pointer;

      &.is-selected {
        border-color: var(--color-brand);
        background: var(--color-brand-soft);
        color: var(--color-brand);
      }
    }

    .quick {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PaymentDialog {
  private readonly orders = inject(ServiceOrdersService);

  readonly open = input(false);
  readonly order = input<ServiceOrder | null>(null);

  readonly saved = output<ServiceOrder>();
  readonly closed = output<void>();

  protected readonly methods = PAYMENT_METHODS;
  protected readonly method = signal<PaymentMethod>('pix');
  protected readonly amount = signal('');
  protected readonly installments = signal(1);
  protected readonly paidAt = signal(today());
  protected readonly notes = signal('');
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly maxDate = today();

  protected readonly balance = computed(() => Math.max(0, this.order()?.balance_cents ?? 0));
  protected readonly amountCents = computed(() => moneyToCents(this.amount()));
  protected readonly half = computed(() => Math.round(this.balance() / 2));
  protected readonly remaining = computed(() => this.balance() - this.amountCents());

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.method.set('pix');
        this.amount.set(centsToInput(this.balance()));
        this.installments.set(1);
        this.paidAt.set(today());
        this.notes.set('');
        this.error.set(null);
        dialog.showModal();
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

  protected setAmount(cents: number): void {
    this.amount.set(centsToInput(cents));
  }

  protected submit(): void {
    const order = this.order();
    if (!order || this.saving()) return;

    if (this.amountCents() <= 0) {
      this.error.set('Informe o valor recebido.');
      return;
    }
    if (this.amountCents() > this.balance()) {
      this.error.set('O valor passa do saldo em aberto.');
      return;
    }

    this.error.set(null);
    this.saving.set(true);
    this.orders
      .addPayment(order.id, {
        method: this.method(),
        amount_cents: this.amountCents(),
        installments: this.method() === 'credit_card' ? this.installments() : 1,
        paid_at: this.paidAt(),
        notes: this.notes(),
      })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (updated) => this.saved.emit(updated),
        error: (error: unknown) => {
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? 'Não foi possível registrar o pagamento.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }
}
