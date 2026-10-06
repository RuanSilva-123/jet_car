import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource } from '../../core/http/api';

export type ReportKey = 'revenue' | 'services' | 'customers' | 'mechanics' | 'warranty' | 'satisfaction';
export type ColumnType = 'text' | 'int' | 'money' | 'percent' | 'date' | 'month' | 'bool' | 'phone';

export interface ReportResult {
  report: ReportKey;
  title: string;
  from: string;
  to: string;
  columns: { key: string; label: string; type: ColumnType }[];
  rows: Record<string, string | number | boolean | null>[];
  totals: Record<string, string | number | boolean | null> | null;
  summary: Record<string, number | null>;
}

export interface ReportQuery {
  report: ReportKey;
  from: string;
  to: string;
  group: 'day' | 'month';
  onlyReturning: boolean;
}

@Injectable({ providedIn: 'root' })
export class ReportsService {
  private readonly http = inject(HttpClient);

  get(query: ReportQuery): Observable<ReportResult> {
    return this.http
      .get<ApiResource<ReportResult>>(`${API_URL}/reports/${query.report}`, { params: this.params(query) })
      .pipe(map(({ data }) => data));
  }

  /** Mesmo domínio: o cookie de sessão autentica o download (como os PDFs). */
  csvUrl(query: ReportQuery): string {
    return `${API_URL}/reports/${query.report}?${this.params(query).set('format', 'csv').toString()}`;
  }

  private params(query: ReportQuery): HttpParams {
    let params = new HttpParams().set('from', query.from).set('to', query.to);
    if (query.report === 'revenue') params = params.set('group', query.group);
    if (query.report === 'customers' && query.onlyReturning) params = params.set('only_returning', 1);
    return params;
  }
}
