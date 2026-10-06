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
  /** Dias de garantia dos serviços (base do retorno em garantia). */
  warranty_days: number;
  pix_key_type: PixKeyType | '';
  pix_key: string;
  pix_beneficiary: string;
  pix_city: string;
}

export type PixKeyType = 'cnpj' | 'cpf' | 'phone' | 'email' | 'random';

/** Resposta do GET/PUT: dados + se o Pix já pode ser gerado + rótulos dos tipos de chave. */
export interface ShopSettingsResponse {
  settings: ShopSettings;
  pixReady: boolean;
  pixKeyTypes: Record<PixKeyType, string>;
}

/** Situação do último backup automático (container "backup"). */
export interface BackupStatus {
  configured: boolean;
  ok: boolean;
  stale: boolean;
  finished_at: string | null;
  db_file: string | null;
  db_size: number;
  files_size: number;
  backups_count: number;
  keep_days: number | null;
  error: string | null;
}

interface SettingsBody {
  data: ShopSettings & { pix_ready: boolean };
  options: { pix_key_types: Record<PixKeyType, string> };
}

function toResponse({ data, options }: SettingsBody): ShopSettingsResponse {
  const { pix_ready, ...settings } = data;
  return { settings, pixReady: pix_ready, pixKeyTypes: options.pix_key_types };
}

@Injectable({ providedIn: 'root' })
export class ShopSettingsService {
  private readonly http = inject(HttpClient);
  private readonly url = `${API_URL}/settings/shop`;

  get(): Observable<ShopSettingsResponse> {
    return this.http.get<SettingsBody>(this.url).pipe(map(toResponse));
  }

  save(settings: ShopSettings): Observable<ShopSettingsResponse> {
    return this.http.put<SettingsBody>(this.url, settings).pipe(map(toResponse));
  }

  backup(): Observable<BackupStatus> {
    return this.http.get<ApiResource<BackupStatus>>(`${API_URL}/settings/backup`).pipe(map(({ data }) => data));
  }
}
