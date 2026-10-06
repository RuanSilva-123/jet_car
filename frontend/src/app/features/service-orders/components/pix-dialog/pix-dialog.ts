import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { PixCharge, PixChargeView } from '../../../../shared/components/pix-charge/pix-charge';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { centsToInput, formatMoney, maskMoney, moneyToCents } from '../../../../shared/utils/br-format';
import { ServiceOrder } from '../../models/service-order';
import { ServiceOrdersService } from '../../services/service-orders.service';

/**
 * Cobrança Pix da OS: QR Code para o cliente escanear no balcão e copia-e-cola para mandar
 * pelo WhatsApp. O Pix é estático (sem banco): ao cair na conta, registre o pagamento.
 */
@Component({
  selector: 'app-pix-dialog',
  imports: [RouterLink, Icon, MoneyPipe, PixChargeView],
  templateUrl: './pix-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  styles: `
    .amount {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
      gap: 10px;

      .field {
        flex: 1 1 180px;
      }
    }

    .state {
      display: grid;
      justify-items: center;
      gap: 10px;
      padding: 24px 8px;
      text-align: center;
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PixDialog {
  private readonly orders = inject(ServiceOrdersService);
  protected readonly isMaster = inject(AuthService).isMaster;

  readonly open = input(false);
  readonly order = input<ServiceOrder | null>(null);

  readonly closed = output<void>();
  /** Atalho para registrar o pagamento depois que o Pix cair. */
  readonly registerPayment = output<void>();

  protected readonly charge = signal<PixCharge | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);
  /** Chave Pix ainda não cadastrada. */
  protected readonly notConfigured = signal(false);
  protected readonly amount = signal('');

  protected readonly balance = computed(() => Math.max(0, this.order()?.balance_cents ?? 0));
  protected readonly amountCents = computed(() => moneyToCents(this.amount()));
  protected readonly amountChanged = computed(() => this.charge() !== null && this.amountCents() !== this.charge()!.amount_cents);

  protected readonly whatsappLink = computed(() => {
    const order = this.order();
    const charge = this.charge();
    if (!order?.customer || !charge) return null;

    const text = [
      `Olá, ${order.customer.name.split(' ')[0]}! Segue o Pix da OS #${order.number}:`,
      '',
      `*Valor: ${formatMoney(charge.amount_cents)}*`,
      `Recebedor: ${charge.beneficiary}`,
      '',
      'Copie o código abaixo e cole na opção "Pix copia e cola" do app do seu banco:',
    ].join('\n');

    // O código vai numa mensagem só dele no fim: fica fácil de copiar no celular
    return `https://wa.me/55${order.customer.phone}?text=${encodeURIComponent(`${text}\n\n${charge.payload}`)}`;
  });

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.charge.set(null);
        this.error.set(null);
        this.notConfigured.set(false);
        this.amount.set(centsToInput(this.balance()));
        dialog.showModal();
        this.generate();
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

  protected generate(): void {
    const order = this.order();
    if (!order || this.loading()) return;

    const cents = this.amountCents();
    if (cents <= 0 || cents > this.balance()) {
      this.error.set(`Informe um valor entre R$ 0,01 e ${formatMoney(this.balance())}.`);
      return;
    }

    this.error.set(null);
    this.loading.set(true);
    this.orders
      .pix(order.id, cents === this.balance() ? null : cents)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (charge) => this.charge.set(charge),
        error: (error: unknown) => {
          this.charge.set(null);
          if (error instanceof HttpErrorResponse && error.status === 404 && /chave pix/i.test(error.error?.message ?? '')) {
            this.notConfigured.set(true);
            return;
          }
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? (error instanceof HttpErrorResponse ? error.error?.message : null) ?? 'Não foi possível gerar o Pix.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    this.closed.emit();
  }
}
