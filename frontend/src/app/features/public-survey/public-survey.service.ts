import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable, switchMap } from 'rxjs';

import { API_URL, ApiResource, CSRF_COOKIE_URL } from '../../core/http/api';

export interface PublicSurvey {
  /** open = pode responder; answered = já respondeu; unavailable = OS reaberta/cancelada. */
  state: 'open' | 'answered' | 'unavailable';
  shop: { name: string; phone: string };
  order: { number: string; customer_first_name: string; vehicle: string; delivered_at: string | null };
  score: number | null;
  comment: string | null;
}

/** Pesquisa de satisfação aberta pelo cliente (link assinado, sem login). */
@Injectable({ providedIn: 'root' })
export class PublicSurveyService {
  private readonly http = inject(HttpClient);

  private url(token: string): string {
    return `${API_URL}/public/surveys/${encodeURIComponent(token)}`;
  }

  get(token: string): Observable<PublicSurvey> {
    return this.http.get<ApiResource<PublicSurvey>>(this.url(token)).pipe(map(({ data }) => data));
  }

  /** Mesmo domínio do painel: o POST precisa do cookie XSRF-TOKEN. */
  answer(token: string, score: number, comment: string): Observable<PublicSurvey> {
    return this.http.get<void>(CSRF_COOKIE_URL).pipe(
      switchMap(() => this.http.post<ApiResource<PublicSurvey>>(this.url(token), { score, comment })),
      map(({ data }) => data),
    );
  }
}
