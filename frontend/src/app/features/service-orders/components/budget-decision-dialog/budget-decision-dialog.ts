import { ChangeDetectionStrategy, Component, effect, ElementRef, input, output, signal, viewChild } from '@angular/core';

import { Icon } from '../../../../shared/components/icon/icon';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';

export type BudgetDecision = { approved: true; note: string } | { approved: false; note: string; cancel: boolean };

/**
 * Registro da resposta do cliente ao orçamento.
 * Aprovar inicia o serviço; recusar volta para revisão ou cancela a OS.
 */
@Component({
  selector: 'app-budget-decision-dialog',
  imports: [Icon, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  styleUrl: '../status-dialog/status-dialog.scss',
  styles: `
    .summary {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 14px;
      border-radius: var(--radius-md);
      background: var(--color-hover);

      strong {
        font-family: var(--font-display);
        font-size: 18px;
      }
    }

    .choices-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }

    .optional {
      color: var(--color-text-muted);
      font-weight: 400;
    }
  `,
  template: `
    <dialog #dialog (cancel)="onCancel($event)" aria-labelledby="decision-title">
      <header class="dialog__header">
        <div>
          <h2 id="decision-title">{{ mode() === 'approve' ? 'Registrar aprovação' : 'Cliente recusou o orçamento' }}</h2>
          <p>
            {{ mode() === 'approve' ? 'O serviço será iniciado em seguida.' : 'Escolha o que fazer com esta OS.' }}
          </p>
        </div>
        <button type="button" class="btn btn--icon" (click)="closed.emit()" aria-label="Fechar" [disabled]="busy()">
          <app-icon name="close" [size]="18" />
        </button>
      </header>

      <div class="dialog__body">
        <div class="summary">
          <span>Valor do orçamento</span>
          <strong>{{ total() | money }}</strong>
        </div>

        <div class="field">
          <label for="decision-note">
            {{ mode() === 'approve' ? 'Como o cliente aprovou?' : 'Motivo' }} <span class="optional">(opcional)</span>
          </label>
          <div class="field__control field__control--textarea">
            <textarea
              id="decision-note"
              rows="3"
              [value]="note()"
              (input)="note.set($any($event.target).value)"
              [placeholder]="mode() === 'approve' ? 'Ex.: aprovou pelo WhatsApp / assinou o orçamento' : 'Ex.: achou caro, vai fazer só a correia'"
              maxlength="1000"
            ></textarea>
          </div>
        </div>
      </div>

      <footer class="dialog__footer">
        <button type="button" class="btn btn--ghost" (click)="closed.emit()" [disabled]="busy()">Voltar</button>
        @if (mode() === 'approve') {
          <button type="button" class="btn btn--primary" (click)="approve()" [disabled]="busy()">
            @if (busy()) {
              <span class="spinner"></span>
            } @else {
              <app-icon name="check" [size]="16" />
            }
            Aprovado, iniciar serviço
          </button>
        } @else {
          <button type="button" class="btn btn--ghost" (click)="reject(false)" [disabled]="busy()">
            <app-icon name="pencil" [size]="16" /> Revisar orçamento
          </button>
          <button type="button" class="btn btn--danger" (click)="reject(true)" [disabled]="busy()">
            <app-icon name="close" [size]="16" /> Cancelar OS
          </button>
        }
      </footer>
    </dialog>
  `,
})
export class BudgetDecisionDialog {
  readonly open = input(false);
  readonly mode = input<'approve' | 'reject'>('approve');
  readonly total = input(0);
  readonly busy = input(false);

  readonly confirmed = output<BudgetDecision>();
  readonly closed = output<void>();

  protected readonly note = signal('');

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.note.set('');
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected approve(): void {
    this.confirmed.emit({ approved: true, note: this.note().trim() });
  }

  protected reject(cancel: boolean): void {
    this.confirmed.emit({ approved: false, note: this.note().trim(), cancel });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.busy()) this.closed.emit();
  }
}
