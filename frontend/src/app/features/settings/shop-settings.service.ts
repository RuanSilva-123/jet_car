import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource } from '../../core/http/api';

/** Dados da oficina usados no cabeçalho e no rodapé dos PDFs. */
export interface ShopSettings {
  name: string;
  document: string;
  phone: string;
  email: string;
  address: string;
  budget_validity_days: number;
  warranty_text: string;
  budget_notes: string;
}

@Injectable({ providedIn: 'root' })
export class ShopSettingsService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/settings/shop`;

  get(): Observable<ShopSettings> {
    return this.http.get<ApiResource<ShopSettings>>(this.url).pipe(map(({ data }) => data));
  }

  save(settings: ShopSettings): Observable<ShopSettings> {
    return this.http.put<ApiResource<ShopSettings>>(this.url, settings).pipe(map(({ data }) => data));
  }
}
