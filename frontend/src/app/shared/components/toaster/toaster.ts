import { ChangeDetectionStrategy, Component, inject } from '@angular/core';

import { ToastService } from '../../../core/services/toast.service';
import { Icon } from '../icon/icon';

@Component({
  selector: 'app-toaster',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    :host {
      position: fixed;
      right: 24px;
      bottom: 24px;
      z-index: 100;
      display: grid;
      gap: 10px;
      width: min(380px, calc(100vw - 32px));

      @media (max-width: 600px) {
        right: 16px;
        bottom: 16px;
      }
    }

    .toast {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: var(--radius-md);
      background: var(--color-ink);
      color: #fff;
      box-shadow: var(--shadow-md);
      animation: slide-in 0.2s ease-out;
    }

    .toast__icon {
      display: grid;
      place-items: center;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: var(--color-success);
    }

    .toast--error .toast__icon {
      background: var(--color-brand);
    }

    .toast__message {
      flex: 1;
      font-weight: 500;
    }

    .toast__close {
      display: grid;
      place-items: center;
      padding: 4px;
      border: 0;
      border-radius: var(--radius-sm);
      background: transparent;
      color: #a1a1aa;
      cursor: pointer;

      &:hover {
        color: #fff;
      }
    }

    @keyframes slide-in {
      from {
        opacity: 0;
        transform: translateY(8px);
      }
    }
  `,
  template: `
    @for (toast of toasts(); track toast.id) {
      <div class="toast" [class.toast--error]="toast.type === 'error'" role="status" aria-live="polite">
        <span class="toast__icon"><app-icon [name]="toast.type === 'error' ? 'alert' : 'check'" [size]="14" /></span>
        <span class="toast__message">{{ toast.message }}</span>
        <button type="button" class="toast__close" (click)="dismiss(toast.id)" aria-label="Fechar">
          <app-icon name="close" [size]="16" />
        </button>
      </div>
    }
  `,
})
export class Toaster {
  private readonly toastService = inject(ToastService);

  protected readonly toasts = this.toastService.toasts;

  protected dismiss(id: number): void {
    this.toastService.dismiss(id);
  }
}
