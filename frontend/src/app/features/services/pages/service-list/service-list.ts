import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { catchError, debounceTime, distinctUntilChanged, finalize, map, of, switchMap, tap } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { Paginated } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { ServiceFormDialog } from '../../components/service-form-dialog/service-form-dialog';
import { LaborService, SERVICE_CATEGORIES, ServiceCategory } from '../../models/labor-service';
import { LaborServiceQuery, LaborServicesService } from '../../services/labor-services.service';

const PER_PAGE = 20;

@Component({
  selector: 'app-service-list',
  imports: [Icon, ConfirmDialog, Pagination, ServiceFormDialog, BrFormatPipe],
  templateUrl: './service-list.html',
  styleUrl: './service-list.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ServiceList {
  private readonly services = inject(LaborServicesService);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly canDelete = inject(AuthService).isMaster;
  protected readonly categories = SERVICE_CATEGORIES;
  protected readonly statusFilters: { value: LaborServiceQuery['status']; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'active', label: 'Ativos' },
    { value: 'inactive', label: 'Inativos' },
  ];

  // Filtros
  protected readonly search = signal('');
  protected readonly category = signal<ServiceCategory | 'all'>('all');
  protected readonly status = signal<LaborServiceQuery['status']>('all');
  protected readonly page = signal(1);
  private readonly reloadTick = signal(0);

  // Listagem
  protected readonly result = signal<Paginated<LaborService> | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal(false);
  protected readonly importing = signal(false);

  // Diálogos
  protected readonly formOpen = signal(false);
  protected readonly editing = signal<LaborService | null>(null);
  protected readonly pendingDelete = signal<LaborService | null>(null);
  protected readonly deleting = signal(false);

  protected readonly hasFilters = computed(
    () => this.search().trim() !== '' || this.category() !== 'all' || this.status() !== 'all',
  );

  private readonly query = computed(() => ({
    search: this.search().trim(),
    category: this.category(),
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
          this.services.list(query).pipe(
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

  protected onCategory(value: string): void {
    this.category.set(value as ServiceCategory | 'all');
    this.page.set(1);
  }

  protected onStatus(value: LaborServiceQuery['status']): void {
    this.status.set(value);
    this.page.set(1);
  }

  protected clearFilters(): void {
    this.search.set('');
    this.category.set('all');
    this.status.set('all');
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

  protected openEdit(service: LaborService): void {
    this.editing.set(service);
    this.formOpen.set(true);
  }

  protected closeForm(): void {
    this.formOpen.set(false);
  }

  /** Ativar/desativar é reversível: sem confirmação. */
  protected toggleActive(service: LaborService): void {
    this.services.setActive(service, !service.is_active).subscribe({
      next: () => {
        this.toast.success(service.is_active ? `"${service.name}" desativado.` : `"${service.name}" reativado.`);
        this.reload();
      },
      error: () => this.toast.error('Não foi possível alterar o serviço.'),
    });
  }

  protected importSuggestions(): void {
    this.importing.set(true);
    this.services
      .importSuggestions()
      .pipe(finalize(() => this.importing.set(false)))
      .subscribe({
        next: (created) => {
          this.toast.success(
            created > 0 ? `${created} serviços adicionados. Edite ou remova o que não fizer sentido.` : 'Todos os serviços sugeridos já estão cadastrados.',
          );
          this.clearFilters();
          this.reload();
        },
        error: () => this.toast.error('Não foi possível adicionar a lista sugerida.'),
      });
  }

  protected confirmDelete(): void {
    const service = this.pendingDelete();
    if (!service) return;

    this.deleting.set(true);
    this.services
      .remove(service.id)
      .pipe(finalize(() => this.deleting.set(false)))
      .subscribe({
        next: () => {
          this.toast.success(`"${service.name}" foi excluído.`);
          this.pendingDelete.set(null);
          this.reload();
        },
        error: (error: unknown) => {
          this.pendingDelete.set(null);
          this.toast.error(
            error instanceof HttpErrorResponse && error.status === 403
              ? 'Somente o administrador master pode excluir serviços.'
              : 'Não foi possível excluir o serviço.',
          );
        },
      });
  }
}
