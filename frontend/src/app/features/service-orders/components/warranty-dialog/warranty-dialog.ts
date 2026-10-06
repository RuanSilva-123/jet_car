import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { finalize } from 'rxjs';

import { Icon } from '../../../../shared/components/icon/icon';
import { ServiceOrder, WarrantyCandidate } from '../../models/service-order';
import { ServiceOrdersService } from '../../services/service-orders.service';

/**
 * Marca a OS como retorno em garantia: escolhe a OS original (mesmo veículo, entregue dentro do prazo)
 * e quais serviços estão sendo refeitos. Eles entram nesta OS sem custo.
 */
@Component({
  selector: 'app-warranty-dialog',
  imports: [DatePipe, Icon],
  templateUrl: './warranty-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  styles: `
    .orders {
      display: grid;
      gap: 10px;
    }

    .order {
      display: grid;
      gap: 10px;
      padding: 12px 14px;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      cursor: pointer;

      &.is-selected {
        border-color: var(--color-brand);
        background: var(--color-brand-soft);
      }

      &__head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px 12px;

        input {
          accent-color: var(--color-brand);
        }

        strong {
          font-family: var(--font-display);
        }

        span {
          color: var(--color-text-muted);
          font-size: 13px;
        }
      }

      &__items {
        display: grid;
        gap: 6px;
        padding-left: 26px;
      }
    }

    .state {
      display: grid;
      justify-items: center;
      gap: 8px;
      padding: 20px 8px;
      text-align: center;
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WarrantyDialog {
  private readonly orders = inject(ServiceOrdersService);

  readonly open = input(false);
  readonly order = input<ServiceOrder | null>(null);

  readonly saved = output<ServiceOrder>();
  readonly closed = output<void>();

  protected readonly candidates = signal<WarrantyCandidate[]>([]);
  protected readonly warrantyDays = signal(0);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly selectedOrder = signal<number | null>(null);
  protected readonly selectedItems = signal<ReadonlySet<number>>(new Set());

  protected readonly canSave = computed(() => this.selectedOrder() !== null && this.selectedItems().size > 0 && !this.saving());

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.reset();
        dialog.showModal();
        this.load();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected chooseOrder(candidate: WarrantyCandidate): void {
    if (this.selectedOrder() === candidate.id) return;
    this.selectedOrder.set(candidate.id);
    // Com um serviço só, já vem marcado
    this.selectedItems.set(new Set(candidate.items.length === 1 ? [candidate.items[0].id] : []));
    this.error.set(null);
  }

  protected toggleItem(candidate: WarrantyCandidate, itemId: number): void {
    if (this.selectedOrder() !== candidate.id) {
      this.selectedOrder.set(candidate.id);
      this.selectedItems.set(new Set());
    }
    const next = new Set(this.selectedItems());
    if (next.has(itemId)) next.delete(itemId);
    else next.add(itemId);
    this.selectedItems.set(next);
  }

  protected submit(): void {
    const order = this.order();
    const original = this.selectedOrder();
    if (!order || original === null || this.saving()) return;

    if (this.selectedItems().size === 0) {
      this.error.set('Marque os serviços que estão sendo refeitos.');
      return;
    }

    this.error.set(null);
    this.saving.set(true);
    this.orders
      .linkWarranty(order.id, original, [...this.selectedItems()])
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (updated) => this.saved.emit(updated),
        error: (error: unknown) => {
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? 'Não foi possível marcar o retorno em garantia.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }

  private reset(): void {
    this.candidates.set([]);
    this.selectedOrder.set(null);
    this.selectedItems.set(new Set());
    this.error.set(null);
  }

  private load(): void {
    const order = this.order();
    if (!order) return;

    this.loading.set(true);
    this.orders
      .warrantyCandidates(order.id)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ candidates, warrantyDays }) => {
          this.candidates.set(candidates);
          this.warrantyDays.set(warrantyDays);
          if (candidates.length === 1) this.chooseOrder(candidates[0]);
        },
        error: () => this.error.set('Não foi possível buscar as OS anteriores deste veículo.'),
      });
  }
}
