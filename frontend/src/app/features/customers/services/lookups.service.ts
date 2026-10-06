import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, map, Observable, of, shareReplay } from 'rxjs';

import { API_URL, ApiResource } from '../../../core/http/api';
import { Address, CatalogItem, PlateData, PlateLookupStatus, VehicleType } from '../models/customer';

/**
 * Catálogo FIPE e busca de CEP (via proxy da API, que também faz cache).
 * Respostas do catálogo ficam em memória durante a sessão para não repetir chamadas.
 */
@Injectable({ providedIn: 'root' })
export class LookupsService {
  private readonly http = inject(HttpClient);
  private readonly catalogCache = new Map<string, Observable<CatalogItem[]>>();
  private plateStatus?: Observable<PlateLookupStatus>;

  brands(type: VehicleType): Observable<CatalogItem[]> {
    return this.catalog(`${type}/brands`);
  }

  /** Anos/combustíveis em que a marca tem veículos (ex.: "2022-5" = 2022 Flex). */
  years(type: VehicleType, brand: string): Observable<CatalogItem[]> {
    return this.catalog(`${type}/brands/${brand}/years`);
  }

  /** Modelos da marca naquele ano/combustível. */
  models(type: VehicleType, brand: string, year: string): Observable<CatalogItem[]> {
    return this.catalog(`${type}/brands/${brand}/years/${year}/models`);
  }

  /** Se o preenchimento pela placa está configurado (consultado uma vez por sessão). */
  plateLookupStatus(): Observable<PlateLookupStatus> {
    this.plateStatus ??= this.http.get<ApiResource<PlateLookupStatus>>(`${API_URL}/plate-lookup`).pipe(
      map(({ data }) => data),
      catchError(() => of({ enabled: false, provider: null })),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    return this.plateStatus;
  }

  plate(plate: string): Observable<PlateData> {
    return this.http.get<ApiResource<PlateData>>(`${API_URL}/plate-lookup/${plate}`).pipe(map(({ data }) => data));
  }

  address(zipCode: string): Observable<Address> {
    return this.http.get<ApiResource<Address>>(`${API_URL}/address-lookup/${zipCode}`).pipe(map(({ data }) => data));
  }

  private catalog(path: string): Observable<CatalogItem[]> {
    let request = this.catalogCache.get(path);

    if (!request) {
      request = this.http.get<ApiResource<CatalogItem[]>>(`${API_URL}/vehicle-catalog/${path}`).pipe(
        map(({ data }) => data),
        shareReplay({ bufferSize: 1, refCount: false }),
      );
      this.catalogCache.set(path, request);

      // Erro não fica em cache: a próxima tentativa refaz a chamada
      request.subscribe({ error: () => this.catalogCache.delete(path) });
    }

    return request;
  }
}
