import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { User } from '../../../core/auth/models/user';
import { UsersService } from './users.service';

const user: User = {
  id: 7,
  name: 'João',
  email: 'joao@jetcar.test',
  role: 'admin',
  role_label: 'Administrador',
  is_active: true,
  last_login_at: null,
  created_at: null,
};

describe('UsersService', () => {
  let service: UsersService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(UsersService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('envia só os filtros preenchidos na listagem', () => {
    service.list({ search: '', status: 'all', page: 2, perPage: 10 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/users');
    expect(req.request.params.keys().sort()).toEqual(['page', 'per_page']);
    expect(req.request.params.get('page')).toBe('2');
    req.flush({ data: [], meta: {} });
  });

  it('envia busca e status quando informados', () => {
    service.list({ search: 'joão', status: 'inactive', page: 1, perPage: 10 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/users');
    expect(req.request.params.get('search')).toBe('joão');
    expect(req.request.params.get('status')).toBe('inactive');
    req.flush({ data: [], meta: {} });
  });

  it('setActive mantém os dados e não altera a senha', () => {
    service.setActive(user, false).subscribe();
    const req = http.expectOne('/api/v1/users/7');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({
      name: 'João',
      email: 'joao@jetcar.test',
      role: 'admin',
      is_active: false,
      password: '',
      password_confirmation: '',
    });
    req.flush({ data: { ...user, is_active: false } });
  });

  it('remove chama DELETE', () => {
    service.remove(7).subscribe();
    const req = http.expectOne('/api/v1/users/7');
    expect(req.request.method).toBe('DELETE');
    req.flush(null);
  });
});
