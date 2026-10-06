import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable, switchMap } from 'rxjs';

import { API_URL, ApiResource, CSRF_COOKIE_URL } from '../../core/http/api';

export interface PublicBudget {
  state: 'awaiting' | 'approved' | 'rejected' | 'unavailable';
  shop: { name: string; phone: string; budget_notes: string; warranty_text: string; budget_validity_days: number };
  order: {
    number: string;
    status_label: string;
    created_at: string;
    customer_first_name: string;
    vehicle: { brand: string; model: string; model_year: number | null; plate: string | null };
    complaint: string | null;
    items: { name: string; notes: string | null; price_cents: number | null }[];
    parts: { name: string; quantity: number; unit_price_cents: number | null; total_cents: number | null }[];
    labor_total_cents: number;
    parts_total_cents: number;
    discount_cents: number;
    total_cents: number;
    budget_approved_at: string | null;
  };
}

/** Orçamento aberto pelo cliente pelo link assinado (sem login). */
@Injectable({ providedIn: 'root' })
export class PublicBudgetService {
  private readonly http = inject(HttpClient);

  private url(token: string): string {
    return `${API_URL}/public/budgets/${encodeURIComponent(token)}`;
  }

  get(token: string): Observable<PublicBudget> {
    return this.http.get<ApiResource<PublicBudget>>(this.url(token)).pipe(map(({ data }) => data));
  }

  /** O total visto vai junto: se a oficina mudou o orçamento, a API pede para conferir de novo. */
  approve(token: string, totalCents: number, name: string): Observable<PublicBudget> {
    return this.withCsrf(() =>
      this.http.post<ApiResource<PublicBudget>>(`${this.url(token)}/approve`, { total_cents: totalCents, name }),
    );
  }

  reject(token: string, totalCents: number, reason: string): Observable<PublicBudget> {
    return this.withCsrf(() =>
      this.http.post<ApiResource<PublicBudget>>(`${this.url(token)}/reject`, { total_cents: totalCents, reason }),
    );
  }

  /** Mesmo domínio do painel: o POST precisa do cookie XSRF-TOKEN, como no login. */
  private withCsrf(request: () => Observable<ApiResource<PublicBudget>>): Observable<PublicBudget> {
    return this.http.get<void>(CSRF_COOKIE_URL).pipe(
      switchMap(request),
      map(({ data }) => data),
    );
  }
}
