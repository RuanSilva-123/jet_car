import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource, Paginated } from '../../core/http/api';

export type ReminderStatus = 'pending' | 'contacted' | 'scheduled' | 'dismissed' | 'done';
export type ReminderFilter = 'open' | 'all' | ReminderStatus;

export interface ServiceReminder {
  id: number;
  service_name: string;
  labor_service_id: number | null;
  status: ReminderStatus;
  status_label: string;
  last_done_at: string;
  last_mileage: number | null;
  due_at: string | null;
  due_mileage: number | null;
  is_overdue: boolean;
  notes: string | null;
  contacted_at: string | null;
  contacted_by: string | null;
  customer: { id: number; name: string; phone: string; phone_is_whatsapp: boolean };
  vehicle: { id: number; brand: string; model: string; plate: string | null; mileage: number | null };
}

export type ReminderPage = Paginated<ServiceReminder> & { summary: { pending: number; contacted: number } };

/** Lembretes de revisão (lista gerada todo dia pelo scheduler). */
@Injectable({ providedIn: 'root' })
export class RemindersService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/service-reminders`;

  list(query: { search: string; status: ReminderFilter; page: number }): Observable<ReminderPage> {
    let params = new HttpParams().set('page', query.page).set('status', query.status);
    if (query.search) params = params.set('search', query.search);
    return this.http.get<ReminderPage>(this.url, { params });
  }

  update(id: number, status: ReminderStatus, notes?: string): Observable<ServiceReminder> {
    return this.http
      .patch<ApiResource<ServiceReminder>>(`${this.url}/${id}`, { status, ...(notes !== undefined ? { notes } : {}) })
      .pipe(map(({ data }) => data));
  }

  /** Atualiza a lista agora (o scheduler faz isso todo dia às 6h). */
  refresh(): Observable<{ created: number; closed: number }> {
    return this.http.post<ApiResource<{ created: number; closed: number }>>(`${this.url}/refresh`, {}).pipe(map(({ data }) => data));
  }
}
