import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { User, UserRole } from '../../../core/auth/models/user';
import { API_URL, ApiResource, Paginated } from '../../../core/http/api';

export type UserStatusFilter = 'all' | 'active' | 'inactive';

export interface UserListQuery {
  search: string;
  status: UserStatusFilter;
  page: number;
  perPage: number;
}

export interface UserPayload {
  name: string;
  email: string;
  role: UserRole;
  is_active: boolean;
  /** Obrigatória no cadastro; na edição, vazia = manter a atual. */
  password: string;
  password_confirmation: string;
}

@Injectable({ providedIn: 'root' })
export class UsersService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/users`;

  list(query: UserListQuery): Observable<Paginated<User>> {
    let params = new HttpParams().set('page', query.page).set('per_page', query.perPage);

    if (query.search) {
      params = params.set('search', query.search);
    }
    if (query.status !== 'all') {
      params = params.set('status', query.status);
    }

    return this.http.get<Paginated<User>>(this.url, { params });
  }

  get(id: number): Observable<User> {
    return this.http.get<ApiResource<User>>(`${this.url}/${id}`).pipe(map(({ data }) => data));
  }

  create(payload: UserPayload): Observable<User> {
    return this.http.post<ApiResource<User>>(this.url, payload).pipe(map(({ data }) => data));
  }

  update(id: number, payload: UserPayload): Observable<User> {
    return this.http.put<ApiResource<User>>(`${this.url}/${id}`, payload).pipe(map(({ data }) => data));
  }

  /** Atalho para ativar/desativar mantendo os demais dados. */
  setActive(user: User, isActive: boolean): Observable<User> {
    return this.update(user.id, {
      name: user.name,
      email: user.email,
      role: user.role,
      is_active: isActive,
      password: '',
      password_confirmation: '',
    });
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`);
  }
}
