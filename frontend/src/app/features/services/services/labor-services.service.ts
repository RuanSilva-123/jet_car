import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource, Paginated } from '../../../core/http/api';
import { LaborService, LaborServicePayload, ServiceCategory } from '../models/labor-service';

export interface LaborServiceQuery {
  search: string;
  category: ServiceCategory | 'all';
  status: 'all' | 'active' | 'inactive';
  page: number;
  perPage: number;
}

@Injectable({ providedIn: 'root' })
export class LaborServicesService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/labor-services`;

  list(query: LaborServiceQuery): Observable<Paginated<LaborService>> {
    let params = new HttpParams().set('page', query.page).set('per_page', query.perPage);

    if (query.search) params = params.set('search', query.search);
    if (query.category !== 'all') params = params.set('category', query.category);
    if (query.status !== 'all') params = params.set('status', query.status);

    return this.http.get<Paginated<LaborService>>(this.url, { params });
  }

  create(payload: LaborServicePayload): Observable<LaborService> {
    return this.http.post<ApiResource<LaborService>>(this.url, payload).pipe(map(({ data }) => data));
  }

  update(id: number, payload: LaborServicePayload): Observable<LaborService> {
    return this.http.put<ApiResource<LaborService>>(`${this.url}/${id}`, payload).pipe(map(({ data }) => data));
  }

  setActive(service: LaborService, isActive: boolean): Observable<LaborService> {
    return this.update(service.id, {
      name: service.name,
      category: service.category,
      description: service.description ?? '',
      is_active: isActive,
      // Reenvia o intervalo: a API zera o que não vier
      reminder_months: service.reminder_months,
      reminder_km: service.reminder_km,
    });
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`);
  }

  /** Adiciona a lista sugerida de serviços comuns; retorna quantos foram criados. */
  importSuggestions(): Observable<number> {
    return this.http
      .post<ApiResource<{ created: number }>>(`${this.url}/suggestions`, {})
      .pipe(map(({ data }) => data.created));
  }
}
