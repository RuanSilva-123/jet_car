import {
  ChangeDetectionStrategy,
  Component,
  effect,
  ElementRef,
  input,
  output,
  viewChild,
} from '@angular/core';

import { Icon, IconName } from '../icon/icon';

/**
 * Diálogo de confirmação (usa o <dialog> nativo: foco preso, Esc fecha, fundo escurecido).
 * Controlado pelo pai através do input `open`.
 */
@Component({
  selector: 'app-confirm-dialog',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    dialog {
      width: min(440px, calc(100vw - 32px));
      padding: 0;
      border: 0;
      border-radius: var(--radius-lg);
      background: var(--color-surface);
      color: var(--color-text);
      box-shadow: 0 24px 64px rgb(0 0 0 / 0.25);

      &::backdrop {
        background: rgb(0 0 0 / 0.55);
        backdrop-filter: blur(2px);
      }

      &[open] {
        animation: pop 0.15s ease-out;
      }
    }

    .body {
      display: flex;
      gap: 16px;
      padding: 24px;
    }

    .icon {
      display: grid;
      flex: none;
      place-items: center;
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: var(--color-neutral-soft);
      color: var(--color-text);
    }

    .icon--danger {
      background: var(--color-danger-soft);
      color: var(--color-danger);
    }

    h2 {
      margin-bottom: 6px;
      font-size: 17px;
    }

    p {
      color: var(--color-text-muted);
    }

    .actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding: 16px 24px;
      border-top: 1px solid var(--color-border);
      background: var(--color-hover);
      border-radius: 0 0 var(--radius-lg) var(--radius-lg);
    }

    @keyframes pop {
      from {
        opacity: 0;
        transform: scale(0.97);
      }
    }
  `,
  template: `
    <dialog #dialog (cancel)="onCancel($event)" (click)="onBackdropClick($event)" aria-labelledby="confirm-title">
      <div class="body">
        <span class="icon" [class.icon--danger]="tone() === 'danger'"><app-icon [name]="icon()" [size]="20" /></span>
        <div>
          <h2 id="confirm-title">{{ title() }}</h2>
          <p>{{ message() }}</p>
        </div>
      </div>
      <div class="actions">
        <button type="button" class="btn btn--ghost" (click)="cancelled.emit()" [disabled]="busy()">Cancelar</button>
        <button
          type="button"
          class="btn"
          [class.btn--danger]="tone() === 'danger'"
          [class.btn--primary]="tone() !== 'danger'"
          (click)="confirmed.emit()"
          [disabled]="busy()"
        >
          @if (busy()) {
            <span class="spinner"></span>
          }
          {{ confirmLabel() }}
        </button>
      </div>
    </dialog>
  `,
})
export class ConfirmDialog {
  readonly open = input(false);
  readonly title = input.required<string>();
  readonly message = input.required<string>();
  readonly confirmLabel = input('Confirmar');
  readonly tone = input<'danger' | 'primary'>('primary');
  readonly icon = input<IconName>('alert');
  readonly busy = input(false);

  readonly confirmed = output<void>();
  readonly cancelled = output<void>();

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  /** Esc: o pai decide fechar (mantém `open` como fonte da verdade). */
  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.busy()) {
      this.cancelled.emit();
    }
  }

  /** Clique fora do conteúdo (no backdrop) também cancela. */
  protected onBackdropClick(event: MouseEvent): void {
    if (event.target === this.dialog().nativeElement && !this.busy()) {
      this.cancelled.emit();
    }
  }
}
