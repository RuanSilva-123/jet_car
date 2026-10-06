import { Injectable, signal } from '@angular/core';

export type ToastType = 'success' | 'error';

export interface Toast {
  id: number;
  type: ToastType;
  message: string;
}

/** Notificações rápidas no canto da tela (renderizadas pelo componente Toaster). */
@Injectable({ providedIn: 'root' })
export class ToastService {
  private static readonly DURATION_MS = 4000;

  private nextId = 0;
  private readonly items = signal<Toast[]>([]);

  readonly toasts = this.items.asReadonly();

  success(message: string): void {
    this.show('success', message);
  }

  error(message: string): void {
    this.show('error', message);
  }

  dismiss(id: number): void {
    this.items.update((toasts) => toasts.filter((toast) => toast.id !== id));
  }

  private show(type: ToastType, message: string): void {
    const id = ++this.nextId;
    this.items.update((toasts) => [...toasts, { id, type, message }]);
    setTimeout(() => this.dismiss(id), ToastService.DURATION_MS);
  }
}
