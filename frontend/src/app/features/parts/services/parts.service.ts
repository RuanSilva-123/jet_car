import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource, Paginated } from '../../../core/http/api';
import { Part, PartPayload, StockMovement } from '../models/part';

export interface PartQuery {
  search: string;
  status: 'all' | 'active' | 'inactive' | 'low';
  page: number;
  perPage: number;
}

export type PartPage = Paginated<Part> & { summary: { low_stock: number } };

@Injectable({ providedIn: 'root' })
export class PartsService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/parts`;

  list(query: PartQuery): Observable<PartPage> {
    let params = new HttpParams().set('page', query.page).set('per_page', query.perPage);
    if (query.search) params = params.set('search', query.search);
    if (query.status !== 'all') params = params.set('status', query.status);

    return this.http.get<PartPage>(this.url, { params });
  }

  create(payload: PartPayload): Observable<Part> {
    return this.http.post<ApiResource<Part>>(this.url, payload).pipe(map(({ data }) => data));
  }

  update(id: number, payload: PartPayload): Observable<Part> {
    return this.http.put<ApiResource<Part>>(`${this.url}/${id}`, payload).pipe(map(({ data }) => data));
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`);
  }

  /** Entrada (compra) ou ajuste de inventário (quantidade contada). */
  moveStock(
    id: number,
    movement: {
      type: 'entry' | 'adjustment';
      quantity: number;
      unit_cost_cents: number | null;
      notes: string;
      /** Compra: já lança a conta a pagar (quantidade × custo). */
      create_bill?: boolean;
      bill_due_date?: string | null;
      bill_supplier_id?: number | null;
      bill_installments?: number;
    },
  ): Observable<Part> {
    return this.http.post<ApiResource<Part>>(`${this.url}/${id}/stock`, movement).pipe(map(({ data }) => data));
  }

  movements(id: number, page = 1): Observable<Paginated<StockMovement>> {
    return this.http.get<Paginated<StockMovement>>(`${this.url}/${id}/movements`, { params: new HttpParams().set('page', page) });
  }
}
