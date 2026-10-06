import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { API_URL } from '../../../core/http/api';

export interface Damage {
  area: string;
  type: string;
  notes: string | null;
}

export interface Inspection {
  fuel_level: number | null;
  damages: Damage[];
  checklist: string[];
  belongings: string | null;
  notes: string | null;
  created_by: string | null;
  created_at: string | null;
}

export interface InspectionPhoto {
  id: number;
  caption: string | null;
  url: string;
  created_at: string;
}

export interface InspectionResponse {
  data: Inspection | null;
  photos: InspectionPhoto[];
  options: {
    fuel_levels: string[];
    damage_areas: Record<string, string>;
    damage_types: Record<string, string>;
    checklist: Record<string, string>;
  };
}

export interface InspectionPayload {
  fuel_level: number | null;
  damages: Damage[];
  checklist: string[];
  belongings: string;
  notes: string;
}

/** Vistoria de entrada da OS (dados e fotos). */
@Injectable({ providedIn: 'root' })
export class InspectionService {
  private readonly http = inject(HttpClient);

  private url(orderId: number): string {
    return `${API_URL}/service-orders/${orderId}/inspection`;
  }

  get(orderId: number): Observable<InspectionResponse> {
    return this.http.get<InspectionResponse>(this.url(orderId));
  }

  save(orderId: number, payload: InspectionPayload): Observable<InspectionResponse> {
    return this.http.put<InspectionResponse>(this.url(orderId), payload);
  }

  uploadPhoto(orderId: number, photo: Blob, filename: string, caption = ''): Observable<InspectionResponse> {
    const body = new FormData();
    body.append('photo', photo, filename);
    if (caption) body.append('caption', caption);
    return this.http.post<InspectionResponse>(`${this.url(orderId)}/photos`, body);
  }

  removePhoto(orderId: number, photoId: number): Observable<InspectionResponse> {
    return this.http.delete<InspectionResponse>(`${this.url(orderId)}/photos/${photoId}`);
  }

}
