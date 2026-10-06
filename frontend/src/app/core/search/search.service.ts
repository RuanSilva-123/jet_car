import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource } from '../http/api';

export interface SearchResults {
  vehicles: {
    id: number;
    brand: string;
    model: string;
    model_year: number | null;
    plate: string | null;
    customer: { id: number; name: string };
    active_order: { id: number; number: string; status: string; status_label: string } | null;
  }[];
  customers: { id: number; name: string; document: string | null; phone: string }[];
  orders: { id: number; number: string; status: string; status_label: string; customer: string; vehicle: string; plate: string | null }[];
}

/** Busca global (Ctrl+K): placa, cliente ou número da OS. */
@Injectable({ providedIn: 'root' })
export class SearchService {
  private readonly http = inject(HttpClient);

  search(q: string): Observable<SearchResults> {
    return this.http
      .get<ApiResource<SearchResults>>(`${API_URL}/search`, { params: new HttpParams().set('q', q) })
      .pipe(map(({ data }) => data));
  }
}
