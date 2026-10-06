import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';

import { MoneyPipe } from '../../pipes/money.pipe';
import { formatPixKey } from '../../utils/br-format';
import { Icon } from '../icon/icon';

/** Cobrança Pix gerada pela API (BR Code estático com o valor). */
export interface PixCharge {
  /** Texto do "Pix copia e cola". */
  payload: string;
  /** PNG em data URI. */
  qr_code: string;
  amount_cents: number;
  txid: string;
  key: string;
  key_type: string;
  beneficiary: string;
}

/** QR Code + copia-e-cola + valor. Usado no link público do orçamento e na cobrança da OS. */
@Component({
  selector: 'app-pix-charge',
  imports: [Icon, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="pix">
      <img class="pix__qr" [src]="charge().qr_code" width="220" height="220" alt="QR Code Pix" />
      <div class="pix__info">
        <span class="pix__label">Valor</span>
        <strong class="pix__amount">{{ charge().amount_cents | money }}</strong>
        <span class="pix__label">Recebedor</span>
        <span class="pix__value">{{ charge().beneficiary }}</span>
        <span class="pix__label">Chave Pix</span>
        <span class="pix__value">{{ keyLabel() }}</span>
      </div>
      <div class="pix__copy">
        <span class="pix__label">Pix copia e cola</span>
        <code class="pix__payload">{{ charge().payload }}</code>
        <button type="button" class="btn btn--primary btn--block" (click)="copy()">
          <app-icon [name]="copied() ? 'check' : 'copy'" [size]="16" />
          {{ copied() ? 'Código copiado!' : 'Copiar código Pix' }}
        </button>
      </div>
    </div>
  `,
  styles: `
    .pix {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      gap: 16px 20px;
      align-items: center;
    }

    .pix__qr {
      width: 180px;
      height: 180px;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      background: #fff;
      image-rendering: pixelated;
    }

    .pix__info {
      display: grid;
      gap: 2px;
      min-width: 0;
    }

    .pix__label {
      color: var(--color-text-muted);
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .pix__label:not(:first-child) {
      margin-top: 8px;
    }

    .pix__amount {
      font-family: var(--font-display);
      font-size: 24px;
    }

    .pix__value {
      overflow-wrap: anywhere;
      font-weight: 500;
    }

    .pix__copy {
      display: grid;
      grid-column: 1 / -1;
      gap: 8px;
    }

    .pix__payload {
      display: block;
      max-height: 76px;
      overflow: auto;
      padding: 10px 12px;
      border: 1px dashed var(--color-border);
      border-radius: var(--radius-md);
      background: var(--color-hover);
      font-size: 12px;
      line-height: 1.5;
      overflow-wrap: anywhere;
      user-select: all;
    }

    @media (max-width: 480px) {
      .pix {
        grid-template-columns: minmax(0, 1fr);
        justify-items: center;
        text-align: center;
      }

      .pix__info,
      .pix__copy {
        width: 100%;
      }
    }
  `,
})
export class PixChargeView {
  readonly charge = input.required<PixCharge>();

  protected readonly copied = signal(false);
  protected readonly keyLabel = computed(() => formatPixKey(this.charge().key_type, this.charge().key));

  protected async copy(): Promise<void> {
    const text = this.charge().payload;
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      // Sem permissão de área de transferência (http, navegador antigo): seleciona para o Ctrl+C
      const area = document.createElement('textarea');
      area.value = text;
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      document.execCommand('copy');
      area.remove();
    }
    this.copied.set(true);
    setTimeout(() => this.copied.set(false), 2500);
  }
}
