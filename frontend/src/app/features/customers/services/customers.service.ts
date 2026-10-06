import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource, Paginated } from '../../../core/http/api';
import { Customer, FuelType, PersonType, VehicleType } from '../models/customer';

export type PersonTypeFilter = 'all' | PersonType;

export interface CustomerListQuery {
  search: string;
  personType: PersonTypeFilter;
  page: number;
  perPage: number;
}

export interface VehiclePayload {
  id: number | null;
  type: VehicleType;
  brand: string;
  model: string;
  model_year: number | null;
  manufacture_year: number | null;
  fuel: FuelType | null;
  plate: string;
  color: string;
  mileage: string;
  vin: string;
  renavam: string;
  fipe_brand_code: string | null;
  fipe_model_code: string | null;
  fipe_year_code: string | null;
  notes: string;
}

export interface CustomerPayload {
  person_type: PersonType;
  name: string;
  trade_name: string;
  document: string;
  state_registration: string;
  birth_date: string;
  phone: string;
  phone_is_whatsapp: boolean;
  secondary_phone: string;
  email: string;
  zip_code: string;
  street: string;
  number: string;
  complement: string;
  neighborhood: string;
  city: string;
  state: string;
  notes: string;
  vehicles: VehiclePayload[];
}

@Injectable({ providedIn: 'root' })
export class CustomersService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/customers`;

  list(query: CustomerListQuery): Observable<Paginated<Customer>> {
    let params = new HttpParams().set('page', query.page).set('per_page', query.perPage);

    if (query.search) {
      params = params.set('search', query.search);
    }
    if (query.personType !== 'all') {
      params = params.set('person_type', query.personType);
    }

    return this.http.get<Paginated<Customer>>(this.url, { params });
  }

  get(id: number): Observable<Customer> {
    return this.http.get<ApiResource<Customer>>(`${this.url}/${id}`).pipe(map(({ data }) => data));
  }

  create(payload: CustomerPayload): Observable<Customer> {
    return this.http.post<ApiResource<Customer>>(this.url, payload).pipe(map(({ data }) => data));
  }

  update(id: number, payload: CustomerPayload): Observable<Customer> {
    return this.http.put<ApiResource<Customer>>(`${this.url}/${id}`, payload).pipe(map(({ data }) => data));
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`);
  }
}
