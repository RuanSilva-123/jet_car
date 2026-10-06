import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, finalize, map, of, switchMap, tap } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { Paginated } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { initials } from '../../../../shared/utils/initials';
import { Customer } from '../../models/customer';
import { CustomersService, PersonTypeFilter } from '../../services/customers.service';

const PER_PAGE = 10;
/** Placas mostradas na linha; o resto vira "+N". */
const VISIBLE_PLATES = 2;

@Component({
  selector: 'app-customer-list',
  imports: [RouterLink, Icon, ConfirmDialog, Pagination, BrFormatPipe],
  templateUrl: './customer-list.html',
  styleUrl: './customer-list.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerList {
  private readonly customers = inject(CustomersService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly canDelete = inject(AuthService).isMaster;
  protected readonly initials = initials;
  protected readonly visiblePlates = VISIBLE_PLATES;

  protected readonly typeFilters: { value: PersonTypeFilter; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'individual', label: 'Pessoa física' },
    { value: 'company', label: 'Pessoa jurídica' },
  ];

  protected readonly search = signal('');
  protected readonly personType = signal<PersonTypeFilter>('all');
  protected readonly page = signal(1);
  private readonly reloadTick = signal(0);

  protected readonly result = signal<Paginated<Customer> | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal(false);

  protected readonly pendingDelete = signal<Customer | null>(null);
  protected readonly deleting = signal(false);

  protected readonly hasFilters = computed(() => this.search().trim() !== '' || this.personType() !== 'all');

  private readonly query = computed(() => ({
    search: this.search().trim(),
    personType: this.personType(),
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
          this.customers.list(query).pipe(
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

  protected onSearch(value: string): void {
    this.search.set(value);
    this.page.set(1);
  }

  protected onPersonType(value: PersonTypeFilter): void {
    this.personType.set(value);
    this.page.set(1);
  }

  protected clearFilters(): void {
    this.search.set('');
    this.personType.set('all');
    this.page.set(1);
  }

  protected goToPage(page: number): void {
    this.page.set(page);
  }

  protected reload(): void {
    this.reloadTick.update((tick) => tick + 1);
  }

  protected open(customer: Customer): void {
    this.router.navigate(['/customers', customer.id, 'edit']);
  }

  /** Link do WhatsApp (wa.me) com DDI do Brasil. */
  protected whatsappLink(phone: string): string {
    return `https://wa.me/55${phone}`;
  }

  protected confirmDelete(): void {
    const customer = this.pendingDelete();
    if (!customer) return;

    this.deleting.set(true);
    this.customers
      .remove(customer.id)
      .pipe(finalize(() => this.deleting.set(false)))
      .subscribe({
        next: () => {
          this.toast.success(`${customer.name} foi excluído.`);
          this.pendingDelete.set(null);
          this.reload();
        },
        error: (error: unknown) => {
          this.pendingDelete.set(null);
          this.toast.error(
            error instanceof HttpErrorResponse && error.status === 403
              ? 'Somente o administrador master pode excluir clientes.'
              : 'Não foi possível excluir o cliente.',
          );
        },
      });
  }
}
