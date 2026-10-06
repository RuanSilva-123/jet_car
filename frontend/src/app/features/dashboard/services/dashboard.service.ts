import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource } from '../../../core/http/api';
import { ServiceOrderStatus } from '../../service-orders/models/service-order';
import { BackupStatus } from '../../settings/shop-settings.service';

export interface DashboardOrder {
  id: number;
  number: string;
  status: ServiceOrderStatus;
  customer: string;
  phone: string;
  phone_is_whatsapp: boolean;
  vehicle: string;
  plate: string | null;
  total_cents: number;
  /** Previsão (YYYY-MM-DD), envio do orçamento ou conclusão (ISO). */
  date: string | null;
}

export interface DashboardData {
  generated_at: string;
  budget_days: number;
  status_counts: { status: ServiceOrderStatus; label: string; count: number }[];
  active_total: number;
  overdue: { count: number; items: DashboardOrder[] };
  stale_budgets: { count: number; items: DashboardOrder[] };
  ready_for_pickup: { count: number; items: DashboardOrder[] };
  /** null para quem não acessa o financeiro (mecânico). */
  finance: {
    month: string;
    revenue_cents: number;
    delivered_count: number;
    average_ticket_cents: number;
    previous_revenue_cents: number;
    received_cents: number;
    receivable_cents: number;
    receivable_count: number;
    bills_overdue_count: number;
    bills_overdue_cents: number;
    bills_due_soon_count: number;
    bills_due_soon_cents: number;
    cash_balance_cents: number;
  } | null;
  /** Pesquisa de satisfação dos últimos 90 dias (NPS = % promotores − % detratores). */
  satisfaction: {
    responses: number;
    nps: number | null;
    average: number | null;
    promoters: number;
    passives: number;
    detractors: number;
    days: number;
    recent: { id: number; number: string; customer: string; score: number; comment: string | null; answered_at: string }[];
    /** Entregues nos últimos 30 dias ainda sem avaliação. */
    awaiting: number;
  };
  warranty_returns_month: number;
  /** Só para o master. */
  backup: BackupStatus | null;
  appointments_today: {
    count: number;
    items: { id: number; scheduled_at: string; status: string; customer: string; vehicle: string | null; plate: string | null }[];
  };
  reminders_pending: number;
  low_stock: { count: number; items: { id: number; name: string; unit: string; stock_quantity: number; min_stock: number }[] };
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private readonly http = inject(HttpClient);

  get(budgetDays: number): Observable<DashboardData> {
    return this.http
      .get<ApiResource<DashboardData>>(`${API_URL}/dashboard`, { params: new HttpParams().set('budget_days', budgetDays) })
      .pipe(map(({ data }) => data));
  }
}
