import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';

import { Icon } from '../../../../shared/components/icon/icon';
import { STATUS_ORDER, ServiceOrderStatus } from '../../models/service-order';
import { StatusBadge } from '../status-badge/status-badge';

export interface StatusChange {
  status: ServiceOrderStatus;
  note: string;
}

/** Troca de status com observação opcional (vai para a linha do tempo). */
@Component({
  selector: 'app-status-dialog',
  imports: [Icon, StatusBadge],
  changeDetection: ChangeDetectionStrategy.OnPush,
  styleUrl: './status-dialog.scss',
  template: `
    <dialog #dialog (cancel)="onCancel($event)" aria-labelledby="status-dialog-title">
      <header class="dialog__header">
        <div>
          <h2 id="status-dialog-title">Alterar status</h2>
          <p>Status atual: <app-status-badge [status]="current()" /></p>
        </div>
        <button type="button" class="btn btn--icon" (click)="closed.emit()" aria-label="Fechar" [disabled]="busy()">
          <app-icon name="close" [size]="18" />
        </button>
      </header>

      <div class="dialog__body">
        <div class="options" role="radiogroup" aria-label="Novo status">
          @for (status of options(); track status) {
            <label class="option" [class.is-selected]="selected() === status" [class.is-blocked]="blocked().includes(status)">
              <input
                type="radio"
                name="new-status"
                [checked]="selected() === status"
                [disabled]="blocked().includes(status)"
                (change)="selected.set(status)"
              />
              <app-status-badge [status]="status" />
              <small>{{ blocked().includes(status) ? 'Requer orçamento aprovado pelo cliente.' : hints[status] }}</small>
            </label>
          }
        </div>

        <div class="field">
          <label for="status-note">Observação <span class="optional">(opcional)</span></label>
          <div class="field__control field__control--textarea">
            <textarea
              id="status-note"
              rows="3"
              [value]="note()"
              (input)="note.set($any($event.target).value)"
              placeholder="Ex.: aguardando amortecedor do fornecedor, chega quinta"
              maxlength="1000"
            ></textarea>
          </div>
        </div>
      </div>

      <footer class="dialog__footer">
        <button type="button" class="btn btn--ghost" (click)="closed.emit()" [disabled]="busy()">Cancelar</button>
        <button type="button" class="btn btn--primary" (click)="confirm()" [disabled]="!selected() || busy()">
          @if (busy()) {
            <span class="spinner"></span>
          }
          Confirmar
        </button>
      </footer>
    </dialog>
  `,
})
export class StatusDialog {
  readonly open = input(false);
  readonly current = input.required<ServiceOrderStatus>();
  /** Status já marcado ao abrir (ex.: botão "Aguardando peças"). */
  readonly preselect = input<ServiceOrderStatus | null>(null);
  readonly busy = input(false);
  /** Status indisponíveis (ex.: execução sem orçamento aprovado). */
  readonly blocked = input<ServiceOrderStatus[]>([]);

  readonly confirmed = output<StatusChange>();
  readonly closed = output<void>();

  protected readonly selected = signal<ServiceOrderStatus | null>(null);
  protected readonly note = signal('');
  protected readonly options = computed(() => STATUS_ORDER.filter((status) => status !== this.current()));

  protected readonly hints: Record<ServiceOrderStatus, string> = {
    open: 'Montando o orçamento.',
    in_progress: 'Mecânico trabalhando no veículo.',
    waiting_approval: 'Orçamento enviado, aguardando o cliente.',
    waiting_parts: 'Parado esperando peça chegar.',
    completed: 'Serviço pronto, aguardando retirada.',
    delivered: 'Cliente retirou o veículo. Encerra a OS.',
    canceled: 'Serviço não será feito. Encerra a OS.',
  };

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.selected.set(this.preselect());
        this.note.set('');
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected confirm(): void {
    const status = this.selected();
    if (status) this.confirmed.emit({ status, note: this.note().trim() });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.busy()) this.closed.emit();
  }
}
