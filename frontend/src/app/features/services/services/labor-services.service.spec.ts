import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { LaborService } from '../models/labor-service';
import { LaborServicesService } from './labor-services.service';

const service: LaborService = {
  id: 3,
  name: 'Troca de amortecedor',
  category: 'suspension',
  category_label: 'Suspensão',
  description: null,
  is_active: true,
  created_at: null,
  updated_at: null,
};

describe('LaborServicesService', () => {
  let api: LaborServicesService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(LaborServicesService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('envia só os filtros escolhidos', () => {
    api.list({ search: 'freio', category: 'all', status: 'active', page: 1, perPage: 20 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/labor-services');
    expect(req.request.params.get('search')).toBe('freio');
    expect(req.request.params.get('status')).toBe('active');
    expect(req.request.params.has('category')).toBe(false);
    req.flush({ data: [], meta: {} });
  });

  it('setActive reenvia os dados sem alterar nome e categoria', () => {
    api.setActive(service, false).subscribe();
    const req = http.expectOne('/api/v1/labor-services/3');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ name: 'Troca de amortecedor', category: 'suspension', description: '', is_active: false });
    req.flush({ data: { ...service, is_active: false } });
  });

  it('importa a lista sugerida e devolve quantos foram criados', () => {
    let created = -1;
    api.importSuggestions().subscribe((count) => (created = count));
    http.expectOne('/api/v1/labor-services/suggestions').flush({ data: { created: 46 } });
    expect(created).toBe(46);
  });
});
