import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { catchError, debounceTime, distinctUntilChanged, finalize, map, of, switchMap, tap } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { PartFormDialog } from '../../components/part-form-dialog/part-form-dialog';
import { StockDialog } from '../../components/stock-dialog/stock-dialog';
import { formatStock, Part } from '../../models/part';
import { PartPage, PartQuery, PartsService } from '../../services/parts.service';

const PER_PAGE = 20;

/** Estoque de peças: catálogo com custo, preço, quantidade e aviso de estoque baixo. */
@Component({
  selector: 'app-part-list',
  imports: [Icon, ConfirmDialog, Pagination, BrFormatPipe, MoneyPipe, PartFormDialog, StockDialog],
  templateUrl: './part-list.html',
  styleUrls: ['../../../services/pages/service-list/service-list.scss', './part-list.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PartList {
  private readonly parts = inject(PartsService);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly canDelete = inject(AuthService).isMaster;
  protected readonly stock = formatStock;
  protected readonly statusFilters: { value: PartQuery['status']; label: string }[] = [
    { value: 'active', label: 'Ativas' },
    { value: 'low', label: 'Estoque baixo' },
    { value: 'inactive', label: 'Inativas' },
    { value: 'all', label: 'Todas' },
  ];

  protected readonly search = signal('');
  protected readonly status = signal<PartQuery['status']>('active');
  protected readonly page = signal(1);
  private readonly reloadTick = signal(0);

  protected readonly result = signal<PartPage | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal(false);

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<Part | null>(null);
  protected readonly stockPart = signal<Part | null>(null);
  protected readonly pendingDelete = signal<Part | null>(null);
  protected readonly deleting = signal(false);

  protected readonly hasFilters = computed(() => this.search().trim() !== '' || this.status() !== 'active');

  private readonly query = computed(() => ({
    search: this.search().trim(),
    status: this.status(),
    page: this.page(),
    perPage: PER_PAGE,
    tick: this.reloadTick(),
  }));

  constructor() {
    toObservable(this.query)
      .pipe(
        debounceTime(250),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        tap(() => {
          this.loading.set(true);
          this.loadError.set(false);
        }),
        switchMap((query) =>
          this.parts.list(query).pipe(
            map((result) => ({ result, failed: false })),
            catchError(() => of({ result: null, failed: true })),
          ),
        ),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(({ result, failed }) => {
        this.loading.set(false);
        this.loadError.set(failed);
        if (!result) return;
        if (result.data.length === 0 && result.meta.current_page > 1) {
          this.page.set(result.meta.last_page);
          return;
        }
        this.result.set(result);
      });
  }

  protected details(part: Part): string {
    return [part.part_number ? `Cód. ${part.part_number}` : null, part.brand].filter(Boolean).join(' · ') || '—';
  }

  protected onSearch(value: string): void {
    this.search.set(value);
    this.page.set(1);
  }

  protected onStatus(value: PartQuery['status']): void {
    this.status.set(value);
    this.page.set(1);
  }

  protected clearFilters(): void {
    this.search.set('');
    this.status.set('active');
    this.page.set(1);
  }

  protected goToPage(page: number): void {
    this.page.set(page);
  }

  protected reload(): void {
    this.reloadTick.update((tick) => tick + 1);
  }

  protected openCreate(): void {
    this.editing.set(null);
    this.formOpen.set(true);
  }

  protected openEdit(part: Part): void {
    this.editing.set(part);
    this.formOpen.set(true);
  }

  protected confirmDelete(): void {
    const part = this.pendingDelete();
    if (!part) return;

    this.deleting.set(true);
    this.parts
      .remove(part.id)
      .pipe(finalize(() => this.deleting.set(false)))
      .subscribe({
        next: () => {
          this.toast.success(`"${part.name}" foi excluída.`);
          this.pendingDelete.set(null);
          this.reload();
        },
        error: (error: unknown) => {
          this.pendingDelete.set(null);
          this.toast.error(
            error instanceof HttpErrorResponse && error.status === 403
              ? 'Somente o administrador master pode excluir peças.'
              : 'Não foi possível excluir a peça.',
          );
        },
      });
  }
}
