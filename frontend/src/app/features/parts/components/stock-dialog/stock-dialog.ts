import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { maskMoney, maskQuantity, moneyToCents, parseQuantity } from '../../../../shared/utils/br-format';
import { formatStock, Part, StockMovement } from '../../models/part';
import { PartsService } from '../../services/parts.service';

/**
 * Movimentação do estoque de uma peça: entrada (compra, com custo) ou ajuste de inventário
 * (quantidade contada), mais o histórico de entradas e saídas.
 */
@Component({
  selector: 'app-stock-dialog',
  imports: [DatePipe, RouterLink, Icon, BrFormatPipe, MoneyPipe],
  templateUrl: './stock-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss', './stock-dialog.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockDialog {
  private readonly parts = inject(PartsService);
  private readonly toast = inject(ToastService);

  readonly open = input(false);
  readonly part = input<Part | null>(null);

  readonly saved = output<Part>();
  readonly closed = output<void>();

  protected readonly stock = formatStock;
  protected readonly mode = signal<'entry' | 'adjustment'>('entry');
  protected readonly quantity = signal('');
  protected readonly cost = signal('');
  protected readonly notes = signal('');
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly movements = signal<StockMovement[]>([]);
  protected readonly movementsPage = signal(1);
  protected readonly hasMore = signal(false);
  protected readonly loadingMovements = signal(false);

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      const part = this.part();
      if (this.open() && part && !dialog.open) {
        this.mode.set('entry');
        this.quantity.set('');
        this.cost.set('');
        this.notes.set('');
        this.error.set(null);
        this.movements.set([]);
        this.loadMovements(part, 1);
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected onQuantity(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = maskQuantity(element.value);
    this.quantity.set(element.value);
  }

  protected onCost(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = maskMoney(element.value);
    this.cost.set(element.value);
  }

  protected submit(): void {
    const part = this.part();
    if (!part || this.saving()) return;

    const quantity = parseQuantity(this.quantity());
    if (this.quantity().trim() === '' || (this.mode() === 'entry' && quantity <= 0)) {
      this.error.set(this.mode() === 'entry' ? 'Informe a quantidade que entrou.' : 'Informe a quantidade contada.');
      return;
    }

    this.error.set(null);
    this.saving.set(true);
    this.parts
      .moveStock(part.id, {
        type: this.mode(),
        quantity,
        unit_cost_cents: this.mode() === 'entry' && this.cost().trim() ? moneyToCents(this.cost()) : null,
        notes: this.notes(),
      })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (updated) => {
          this.toast.success(this.mode() === 'entry' ? 'Entrada registrada.' : 'Estoque ajustado.');
          this.saved.emit(updated);
          this.closed.emit();
        },
        error: (error: unknown) => {
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? 'Não foi possível movimentar o estoque.');
        },
      });
  }

  protected loadMore(): void {
    const part = this.part();
    if (part) this.loadMovements(part, this.movementsPage() + 1);
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }

  private loadMovements(part: Part, page: number): void {
    this.loadingMovements.set(true);
    this.parts
      .movements(part.id, page)
      .pipe(finalize(() => this.loadingMovements.set(false)))
      .subscribe({
        next: (result) => {
          this.movements.update((list) => (page === 1 ? result.data : [...list, ...result.data]));
          this.movementsPage.set(result.meta.current_page);
          this.hasMore.set(result.meta.current_page < result.meta.last_page);
        },
        error: () => this.toast.error('Não foi possível carregar o histórico do estoque.'),
      });
  }
}
