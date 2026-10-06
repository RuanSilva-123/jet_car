import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { finalize } from 'rxjs';

import { Icon } from '../../../../shared/components/icon/icon';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { centsToInput, maskMoney, moneyToCents } from '../../../../shared/utils/br-format';
import { PAYMENT_METHODS, PaymentMethod } from '../../../service-orders/models/service-order';
import { localDate } from '../../dates';
import { Bill, PayablesService } from '../../payables.service';

/** Baixa de uma conta a pagar: data, forma e o valor efetivamente pago (juros/desconto). */
@Component({
  selector: 'app-pay-bill-dialog',
  imports: [DatePipe, Icon, MoneyPipe],
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dialog #dialog (cancel)="onCancel($event)" aria-labelledby="pay-bill-title">
      <form (submit)="$event.preventDefault(); submit()" novalidate>
        <header class="dialog__header">
          <span class="dialog__icon"><app-icon name="check" [size]="20" /></span>
          <div>
            <h2 id="pay-bill-title">Pagar conta</h2>
            <p>{{ bill()?.description }}</p>
          </div>
          <button type="button" class="btn btn--icon" (click)="closed.emit()" aria-label="Fechar" [disabled]="saving()">
            <app-icon name="close" [size]="18" />
          </button>
        </header>

        <div class="dialog__body">
          <div class="dialog__summary">
            <span>
              @if (bill(); as current) {
                Vencimento {{ current.due_date + 'T12:00:00' | date: 'dd/MM/yyyy' }}
              }
            </span>
            <strong>{{ bill()?.amount_cents ?? 0 | money }}</strong>
          </div>

          <div class="dialog__grid">
            <div class="field">
              <label for="pay-date">Pago em</label>
              <div class="field__control">
                <input id="pay-date" type="date" [max]="today" [value]="paidAt()" (input)="paidAt.set($any($event.target).value)" />
              </div>
            </div>
            <div class="field">
              <label for="pay-amount">Valor pago</label>
              <div class="field__control">
                <span class="field__suffix">R$</span>
                <input id="pay-amount" inputmode="numeric" [value]="amount()" (input)="onAmount($event)" />
              </div>
              @if (difference() > 0) {
                <small class="muted">{{ difference() | money }} de juros/multa</small>
              } @else if (difference() < 0) {
                <small class="muted">{{ -difference() | money }} de desconto</small>
              }
            </div>
          </div>

          <div class="field">
            <label for="pay-method">Forma de pagamento</label>
            <div class="field__control">
              <select id="pay-method" (change)="method.set($any($event.target).value)">
                @for (option of methods; track option.value) {
                  <option [value]="option.value" [selected]="option.value === method()">{{ option.label }}</option>
                }
              </select>
            </div>
          </div>

          @if (error(); as message) {
            <div class="dialog__alert" role="alert"><app-icon name="alert" [size]="16" /> {{ message }}</div>
          }
        </div>

        <footer class="dialog__footer">
          <button type="button" class="btn btn--ghost" (click)="closed.emit()" [disabled]="saving()">Cancelar</button>
          <button type="submit" class="btn btn--primary" [disabled]="saving()">
            @if (saving()) {
              <span class="spinner"></span>
            } @else {
              <app-icon name="check" [size]="16" />
            }
            Confirmar pagamento
          </button>
        </footer>
      </form>
    </dialog>
  `,
})
export class PayBillDialog {
  private readonly payables = inject(PayablesService);

  readonly open = input(false);
  readonly bill = input<Bill | null>(null);

  readonly saved = output<Bill>();
  readonly closed = output<void>();

  protected readonly methods = PAYMENT_METHODS;
  protected readonly today = localDate(new Date());
  protected readonly paidAt = signal(this.today);
  protected readonly amount = signal('');
  protected readonly method = signal<PaymentMethod>('pix');
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly difference = computed(() => {
    const cents = moneyToCents(this.amount());
    return cents > 0 ? cents - (this.bill()?.amount_cents ?? 0) : 0;
  });

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.paidAt.set(this.today);
        this.amount.set(centsToInput(this.bill()?.amount_cents ?? 0));
        this.method.set('pix');
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

  protected submit(): void {
    const bill = this.bill();
    if (!bill || this.saving()) return;

    const cents = moneyToCents(this.amount());
    if (cents <= 0) {
      this.error.set('Informe o valor pago.');
      return;
    }

    this.error.set(null);
    this.saving.set(true);
    this.payables
      .payBill(bill.id, { paid_at: this.paidAt(), payment_method: this.method(), amount_cents: cents === bill.amount_cents ? null : cents })
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
