import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource, Paginated } from '../../../core/http/api';
import { Customer, Vehicle } from '../../customers/models/customer';
import { PaymentMethod, ServiceOrder, ServiceOrderPayload, ServiceOrderStatus } from '../models/service-order';

export type ServiceOrderStatusFilter = 'active' | 'all' | ServiceOrderStatus;

export interface ServiceOrderQuery {
  search: string;
  status: ServiceOrderStatusFilter;
  page: number;
  perPage: number;
  vehicleId?: number;
  /** "me" = serviços atribuídos ao usuário logado. */
  mechanicId?: number | 'me';
}

export interface NewPart {
  /** Do estoque: nome/código/preço vêm do catálogo e a quantidade sai do estoque. */
  part_id?: number | null;
  name: string;
  part_number: string;
  quantity: number;
}

export interface NewPayment {
  method: PaymentMethod;
  amount_cents: number;
  installments: number;
  paid_at: string;
  notes: string;
}

export interface VehicleHistory {
  vehicle: Vehicle;
  customer: { id: number; name: string; phone: string; deleted: boolean };
  orders: ServiceOrder[];
}

@Injectable({ providedIn: 'root' })
export class ServiceOrdersService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/service-orders`;

  list(query: ServiceOrderQuery): Observable<Paginated<ServiceOrder>> {
    let params = new HttpParams().set('page', query.page).set('per_page', query.perPage);

    if (query.search) params = params.set('search', query.search);
    if (query.status !== 'all') params = params.set('status', query.status);
    if (query.vehicleId) params = params.set('vehicle_id', query.vehicleId);
    if (query.mechanicId) params = params.set('mechanic_id', query.mechanicId);

    return this.http.get<Paginated<ServiceOrder>>(this.url, { params });
  }

  get(id: number): Observable<ServiceOrder> {
    return this.http.get<ApiResource<ServiceOrder>>(`${this.url}/${id}`).pipe(map(({ data }) => data));
  }

  create(payload: ServiceOrderPayload): Observable<ServiceOrder> {
    return this.http.post<ApiResource<ServiceOrder>>(this.url, payload).pipe(map(({ data }) => data));
  }

  update(id: number, payload: ServiceOrderPayload): Observable<ServiceOrder> {
    return this.http.put<ApiResource<ServiceOrder>>(`${this.url}/${id}`, payload).pipe(map(({ data }) => data));
  }

  changeStatus(id: number, status: ServiceOrderStatus, note: string): Observable<ServiceOrder> {
    return this.http
      .post<ApiResource<ServiceOrder>>(`${this.url}/${id}/status`, { status, note })
      .pipe(map(({ data }) => data));
  }

  setItemDone(id: number, itemId: number, isDone: boolean): Observable<ServiceOrder> {
    return this.http
      .patch<ApiResource<ServiceOrder>>(`${this.url}/${id}/items/${itemId}`, { is_done: isDone })
      .pipe(map(({ data }) => data));
  }

  /** Diagnóstico: serviço do catálogo que precisa ser feito (valor definido no orçamento). */
  addItem(id: number, laborServiceId: number, notes = ''): Observable<ServiceOrder> {
    return this.http
      .post<ApiResource<ServiceOrder>>(`${this.url}/${id}/items`, { labor_service_id: laborServiceId, notes })
      .pipe(map(({ data }) => data));
  }

  removeItem(id: number, itemId: number): Observable<ServiceOrder> {
    return this.http.delete<ApiResource<ServiceOrder>>(`${this.url}/${id}/items/${itemId}`).pipe(map(({ data }) => data));
  }

  /** Diagnóstico: peça necessária (valor definido no orçamento). */
  addPart(id: number, part: NewPart): Observable<ServiceOrder> {
    return this.http.post<ApiResource<ServiceOrder>>(`${this.url}/${id}/parts`, part).pipe(map(({ data }) => data));
  }

  removePart(id: number, partId: number): Observable<ServiceOrder> {
    return this.http.delete<ApiResource<ServiceOrder>>(`${this.url}/${id}/parts/${partId}`).pipe(map(({ data }) => data));
  }

  sendBudget(id: number): Observable<ServiceOrder> {
    return this.http.post<ApiResource<ServiceOrder>>(`${this.url}/${id}/budget/send`, {}).pipe(map(({ data }) => data));
  }

  approveBudget(id: number, note: string): Observable<ServiceOrder> {
    return this.http
      .post<ApiResource<ServiceOrder>>(`${this.url}/${id}/budget/approve`, { note })
      .pipe(map(({ data }) => data));
  }

  /** cancel = true cancela a OS; false volta o orçamento para revisão. */
  rejectBudget(id: number, note: string, cancel: boolean): Observable<ServiceOrder> {
    return this.http
      .post<ApiResource<ServiceOrder>>(`${this.url}/${id}/budget/reject`, { note, cancel })
      .pipe(map(({ data }) => data));
  }

  /** Mecânico responsável pelo serviço (null = sem responsável). */
  assignMechanic(id: number, itemId: number, mechanicId: number | null): Observable<ServiceOrder> {
    return this.http
      .put<ApiResource<ServiceOrder>>(`${this.url}/${id}/items/${itemId}/mechanic`, { mechanic_id: mechanicId })
      .pipe(map(({ data }) => data));
  }

  /** Mecânicos ativos (opções de responsável). */
  mechanics(): Observable<{ id: number; name: string }[]> {
    return this.http.get<ApiResource<{ id: number; name: string }[]>>(`${API_URL}/mechanics`).pipe(map(({ data }) => data));
  }

  addPayment(id: number, payment: NewPayment): Observable<ServiceOrder> {
    return this.http.post<ApiResource<ServiceOrder>>(`${this.url}/${id}/payments`, payment).pipe(map(({ data }) => data));
  }

  removePayment(id: number, paymentId: number): Observable<ServiceOrder> {
    return this.http.delete<ApiResource<ServiceOrder>>(`${this.url}/${id}/payments/${paymentId}`).pipe(map(({ data }) => data));
  }

  /**
   * Endereço do PDF (mesmo domínio: o cookie de sessão autentica a abertura em nova aba).
   * budget = orçamento para o cliente; report = comprovante do serviço realizado.
   */
  pdfUrl(id: number, document: 'budget' | 'report' | 'inspection', download = false): string {
    return `${this.url}/${id}/pdf/${document}${download ? '?download=1' : ''}`;
  }

  addNote(id: number, note: string): Observable<ServiceOrder> {
    return this.http.post<ApiResource<ServiceOrder>>(`${this.url}/${id}/notes`, { note }).pipe(map(({ data }) => data));
  }

  vehicleHistory(vehicleId: number): Observable<VehicleHistory> {
    return this.http
      .get<ApiResource<VehicleHistory>>(`${API_URL}/vehicles/${vehicleId}/history`)
      .pipe(map(({ data }) => data));
  }

  /** Clientes para o seletor da OS (busca na API). */
  searchCustomers(search: string): Observable<Customer[]> {
    const params = new HttpParams().set('search', search).set('per_page', 20);
    return this.http.get<Paginated<Customer>>(`${API_URL}/customers`, { params }).pipe(map(({ data }) => data));
  }
}
