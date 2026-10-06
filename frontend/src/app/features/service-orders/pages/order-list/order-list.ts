import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, map, of, switchMap, tap } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { Paginated } from '../../../../core/http/api';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { StatusBadge } from '../../components/status-badge/status-badge';
import { ServiceOrder } from '../../models/service-order';
import { ServiceOrdersService, ServiceOrderStatusFilter } from '../../services/service-orders.service';

const PER_PAGE = 15;

@Component({
  selector: 'app-order-list',
  imports: [RouterLink, DatePipe, Icon, Pagination, BrFormatPipe, MoneyPipe, StatusBadge],
  templateUrl: './order-list.html',
  styleUrl: './order-list.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrderList {
  private readonly orders = inject(ServiceOrdersService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly statusFilters: { value: ServiceOrderStatusFilter; label: string }[] = [
    { value: 'active', label: 'Em aberto' },
    { value: 'completed', label: 'Concluídas' },
    { value: 'delivered', label: 'Entregues' },
    { value: 'canceled', label: 'Canceladas' },
    { value: 'all', label: 'Todas' },
  ];

  protected readonly today = new Date().toISOString().slice(0, 10);

  protected readonly search = signal('');
  protected readonly status = signal<ServiceOrderStatusFilter>('active');
  protected readonly page = signal(1);
  /** Mecânico vê por padrão só as OS com serviços atribuídos a ele. */
  protected readonly isMechanic = inject(AuthService).isMechanic;
  protected readonly onlyMine = signal(this.isMechanic());
  private readonly reloadTick = signal(0);

  protected readonly result = signal<Paginated<ServiceOrder> | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal(false);

  protected readonly hasSearch = computed(() => this.search().trim() !== '');

  private readonly query = computed(() => ({
    search: this.search().trim(),
    status: this.status(),
    page: this.page(),
    perPage: PER_PAGE,
    mechanicId: this.onlyMine() ? ('me' as const) : undefined,
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
          this.orders.list(query).pipe(
            map((result) => ({ result, failed: false })),
            catchError(() => of({ result: null, failed: true })),
          ),
        ),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(({ result, failed }) => {
        this.loading.set(false);
        this.loadError.set(failed);
        if (result) this.result.set(result);
      });
  }

  protected onSearch(value: string): void {
    this.search.set(value);
    this.page.set(1);
  }

  protected onStatus(value: ServiceOrderStatusFilter): void {
    this.status.set(value);
    this.page.set(1);
  }

  protected goToPage(page: number): void {
    this.page.set(page);
  }

  protected reload(): void {
    this.reloadTick.update((tick) => tick + 1);
  }

  protected open(order: ServiceOrder): void {
    this.router.navigate(['/service-orders', order.id]);
  }

  protected progress(order: ServiceOrder): number {
    return order.items_count ? Math.round(((order.done_items_count ?? 0) / order.items_count) * 100) : 0;
  }

  /** Previsão vencida numa OS ainda em aberto. */
  protected isLate(order: ServiceOrder): boolean {
    return !!order.expected_at && !order.is_final && order.status !== 'completed' && order.expected_at < this.today;
  }
}
