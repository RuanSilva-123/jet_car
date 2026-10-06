import { HttpClient } from '@angular/common/http';
import { computed, inject, Injectable, signal } from '@angular/core';
import { firstValueFrom, map, Observable, switchMap, tap } from 'rxjs';

import { API_URL, ApiResource, CSRF_COOKIE_URL } from '../../http/api';
import { LoginCredentials, User } from '../models/user';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);

  private readonly currentUser = signal<User | null>(null);
  private pendingSessionCheck: Promise<boolean> | null = null;

  readonly user = this.currentUser.asReadonly();
  readonly isAuthenticated = computed(() => this.currentUser() !== null);
  readonly isMaster = computed(() => this.currentUser()?.role === 'master');
  readonly isMechanic = computed(() => this.currentUser()?.role === 'mechanic');
  /** Financeiro: toda a equipe, menos o mecânico (mesma regra da API: gate manage-finance). */
  readonly canManageFinance = computed(() => {
    const user = this.currentUser();
    return !!user && (user.can_manage_finance ?? user.role !== 'mechanic');
  });

  /**
   * Verifica se existe uma sessão válida no servidor (cookie HttpOnly).
   * Chamadas simultâneas compartilham a mesma requisição.
   */
  ensureSession(): Promise<boolean> {
    if (this.currentUser()) {
      return Promise.resolve(true);
    }

    this.pendingSessionCheck ??= firstValueFrom(this.http.get<ApiResource<User>>(`${API_URL}/auth/me`))
      .then(({ data }) => {
        this.currentUser.set(data);
        return true;
      })
      .catch(() => false)
      .finally(() => (this.pendingSessionCheck = null));

    return this.pendingSessionCheck;
  }

  login(credentials: LoginCredentials): Observable<User> {
    // O cookie XSRF-TOKEN precisa existir antes do POST; o HttpClient o envia no header X-XSRF-TOKEN.
    return this.http.get<void>(CSRF_COOKIE_URL).pipe(
      switchMap(() => this.http.post<ApiResource<User>>(`${API_URL}/auth/login`, credentials)),
      map(({ data }) => data),
      tap((user) => this.currentUser.set(user)),
    );
  }

  logout(): Observable<void> {
    return this.http.post<void>(`${API_URL}/auth/logout`, {}).pipe(tap(() => this.clearSession()));
  }

  /** Limpa o estado local (ex.: sessão expirada detectada pelo interceptor). */
  clearSession(): void {
    this.currentUser.set(null);
  }
}
