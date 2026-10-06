import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource } from '../../core/http/api';

export type AppointmentStatus = 'scheduled' | 'confirmed' | 'arrived' | 'no_show' | 'canceled';

export interface Appointment {
  id: number;
  scheduled_at: string;
  ends_at: string;
  duration_minutes: number;
  notes: string | null;
  status: AppointmentStatus;
  status_label: string;
  service_reminder_id: number | null;
  created_by: string | null;
  customer: { id: number; name: string; phone: string; phone_is_whatsapp: boolean; deleted: boolean };
  vehicle: { id: number; brand: string; model: string; plate: string | null } | null;
  service_order: { id: number; number: string } | null;
}

export interface AppointmentPayload {
  customer_id: number;
  vehicle_id: number | null;
  /** ISO 8601 com fuso. */
  scheduled_at: string;
  duration_minutes: number;
  notes: string;
  service_reminder_id?: number | null;
}

@Injectable({ providedIn: 'root' })
export class AgendaService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/appointments`;

  list(from: Date, to: Date): Observable<Appointment[]> {
    const params = new HttpParams().set('from', from.toISOString()).set('to', to.toISOString());
    return this.http.get<ApiResource<Appointment[]>>(this.url, { params }).pipe(map(({ data }) => data));
  }

  create(payload: AppointmentPayload): Observable<Appointment> {
    return this.http.post<ApiResource<Appointment>>(this.url, payload).pipe(map(({ data }) => data));
  }

  update(id: number, payload: AppointmentPayload): Observable<Appointment> {
    return this.http.put<ApiResource<Appointment>>(`${this.url}/${id}`, payload).pipe(map(({ data }) => data));
  }

  changeStatus(id: number, status: AppointmentStatus): Observable<Appointment> {
    return this.http.post<ApiResource<Appointment>>(`${this.url}/${id}/status`, { status }).pipe(map(({ data }) => data));
  }

  /** O carro chegou: abre a OS. Retorna o id da OS criada. */
  checkIn(id: number, vehicleId: number | null, mileage: number | null): Observable<number> {
    return this.http
      .post<ApiResource<{ service_order_id: number }>>(`${this.url}/${id}/check-in`, { vehicle_id: vehicleId, mileage })
      .pipe(map(({ data }) => data.service_order_id));
  }
}

/** Rótulo e cor de cada situação. */
export const APPOINTMENT_STATUS: Record<AppointmentStatus, { label: string; tone: string }> = {
  scheduled: { label: 'Agendado', tone: 'info' },
  confirmed: { label: 'Confirmado', tone: 'success' },
  arrived: { label: 'Chegou', tone: 'ink' },
  no_show: { label: 'Não compareceu', tone: 'warning' },
  canceled: { label: 'Cancelado', tone: 'neutral' },
};
