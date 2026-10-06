import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, finalize, map, Observable, of, switchMap, tap } from 'rxjs';

import { User } from '../../../../core/auth/models/user';
import { AuthService } from '../../../../core/auth/services/auth.service';
import { Paginated } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { initials } from '../../../../shared/utils/initials';
import { UsersService, UserStatusFilter } from '../../services/users.service';

type PendingAction = { kind: 'delete' | 'deactivate'; user: User };

const PER_PAGE = 10;

@Component({
  selector: 'app-user-list',
  imports: [RouterLink, DatePipe, Icon, ConfirmDialog, Pagination],
  templateUrl: './user-list.html',
  styleUrl: './user-list.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UserList {
  private readonly users = inject(UsersService);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly auth = inject(AuthService);

  protected readonly currentUserId = computed(() => this.auth.user()?.id);
  protected readonly initials = initials;

  protected readonly statusFilters: { value: UserStatusFilter; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'active', label: 'Ativos' },
    { value: 'inactive', label: 'Inativos' },
  ];

  // Filtros
  protected readonly search = signal('');
  protected readonly status = signal<UserStatusFilter>('all');
  protected readonly page = signal(1);
  private readonly reloadTick = signal(0);

  // Estado da listagem
  protected readonly result = signal<Paginated<User> | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal(false);

  // Confirmação de ações destrutivas
  protected readonly pending = signal<PendingAction | null>(null);
  protected readonly acting = signal(false);

  protected readonly hasFilters = computed(() => this.search().trim() !== '' || this.status() !== 'all');

  protected readonly confirmTitle = computed(() =>
    this.pending()?.kind === 'delete' ? 'Excluir usuário?' : 'Desativar usuário?',
  );

  protected readonly confirmMessage = computed(() => {
    const action = this.pending();
    if (!action) return '';
    return action.kind === 'delete'
      ? `A conta de ${action.user.name} será removida permanentemente. Esta ação não pode ser desfeita.`
      : `${action.user.name} perderá o acesso ao painel imediatamente. Você pode reativar a conta depois.`;
  });

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
        // Digitação na busca espera uma pausa; troca de página/filtro também passa por aqui
        debounceTime(250),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        tap(() => {
          this.loading.set(true);
          this.loadError.set(false);
        }),
        switchMap((query) =>
          this.users.list(query).pipe(
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

        // Página ficou vazia (ex.: excluiu o último item dela): volta uma página
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

  protected onStatus(value: UserStatusFilter): void {
    this.status.set(value);
    this.page.set(1);
  }

  protected clearFilters(): void {
    this.search.set('');
    this.status.set('all');
    this.page.set(1);
  }

  protected goToPage(page: number): void {
    this.page.set(page);
  }

  protected reload(): void {
    this.reloadTick.update((tick) => tick + 1);
  }

  protected toggleActive(user: User): void {
    if (user.is_active) {
      this.pending.set({ kind: 'deactivate', user });
      return;
    }

    // Reativar não precisa de confirmação
    this.users.setActive(user, true).subscribe({
      next: () => {
        this.toast.success(`${user.name} foi reativado.`);
        this.reload();
      },
      error: (error: unknown) => this.toast.error(this.describeError(error)),
    });
  }

  protected askDelete(user: User): void {
    this.pending.set({ kind: 'delete', user });
  }

  protected cancelPending(): void {
    this.pending.set(null);
  }

  protected confirmPending(): void {
    const action = this.pending();
    if (!action) return;

    this.acting.set(true);
    const request: Observable<unknown> =
      action.kind === 'delete' ? this.users.remove(action.user.id) : this.users.setActive(action.user, false);

    request.pipe(finalize(() => this.acting.set(false))).subscribe({
      next: () => {
        this.toast.success(
          action.kind === 'delete' ? `${action.user.name} foi excluído.` : `${action.user.name} foi desativado.`,
        );
        this.pending.set(null);
        this.reload();
      },
      error: (error: unknown) => {
        this.toast.error(this.describeError(error));
        this.pending.set(null);
      },
    });
  }

  private describeError(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      const firstValidationError = Object.values(error.error?.errors ?? {})[0] as string[] | undefined;
      return firstValidationError?.[0] ?? error.error?.message ?? 'Não foi possível concluir a ação.';
    }
    return 'Não foi possível concluir a ação.';
  }
}
