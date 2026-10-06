import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { API_URL, Paginated } from '../../core/http/api';
import { PaymentMethod, ServiceOrder } from '../service-orders/models/service-order';

export type ReceivableScope = 'all' | 'delivered' | 'in_service';

export interface ReceivablesPage extends Paginated<ServiceOrder> {
  summary: Record<ReceivableScope, { count: number; balance_cents: number }>;
}

export interface PaymentRow {
  id: number;
  method: PaymentMethod;
  method_label: string;
  amount_cents: number;
  installments: number;
  paid_at: string;
  notes: string | null;
  received_by: string | null;
  order: { id: number; number: string; customer: string; vehicle: string; plate: string | null };
}

export interface PaymentsPage extends Paginated<PaymentRow> {
  summary: {
    from: string;
    to: string;
    total_cents: number;
    count: number;
    by_method: { method: PaymentMethod; method_label: string; count: number; total_cents: number }[];
  };
}

@Injectable({ providedIn: 'root' })
export class FinanceService {
  private readonly http = inject(HttpClient);

  receivables(query: { search: string; scope: ReceivableScope; page: number }): Observable<ReceivablesPage> {
    let params = new HttpParams().set('page', query.page).set('scope', query.scope).set('per_page', 15);
    if (query.search) params = params.set('search', query.search);
    return this.http.get<ReceivablesPage>(`${API_URL}/receivables`, { params });
  }

  payments(query: { from: string; to: string; method: PaymentMethod | 'all'; page: number }): Observable<PaymentsPage> {
    let params = new HttpParams().set('page', query.page).set('from', query.from).set('to', query.to).set('per_page', 20);
    if (query.method !== 'all') params = params.set('method', query.method);
    return this.http.get<PaymentsPage>(`${API_URL}/payments`, { params });
  }
}
