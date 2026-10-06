import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { User } from '../models/user';
import { AuthService } from './auth.service';

const user: User = {
  id: 1,
  name: 'Admin Master',
  email: 'master@jetcar.test',
  role: 'master',
  role_label: 'Administrador Master',
  is_active: true,
  last_login_at: null,
  created_at: null,
};

describe('AuthService', () => {
  let service: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('busca o cookie CSRF antes de enviar o login e guarda o usuário', () => {
    service.login({ email: user.email, password: 'secret', remember: false }).subscribe();

    http.expectOne('/sanctum/csrf-cookie').flush(null);
    const login = http.expectOne('/api/v1/auth/login');
    expect(login.request.method).toBe('POST');
    login.flush({ data: user });

    expect(service.user()).toEqual(user);
    expect(service.isAuthenticated()).toBe(true);
    expect(service.isMaster()).toBe(true);
  });

  it('ensureSession retorna false quando não há sessão', async () => {
    const result = service.ensureSession();
    http.expectOne('/api/v1/auth/me').flush(null, { status: 401, statusText: 'Unauthorized' });

    expect(await result).toBe(false);
    expect(service.isAuthenticated()).toBe(false);
  });

  it('ensureSession compartilha a mesma requisição entre chamadas simultâneas', async () => {
    const first = service.ensureSession();
    const second = service.ensureSession();
    http.expectOne('/api/v1/auth/me').flush({ data: user });

    expect(await first).toBe(true);
    expect(await second).toBe(true);
  });

  it('logout limpa o usuário', () => {
    service.login({ email: user.email, password: 'secret', remember: false }).subscribe();
    http.expectOne('/sanctum/csrf-cookie').flush(null);
    http.expectOne('/api/v1/auth/login').flush({ data: user });

    service.logout().subscribe();
    http.expectOne('/api/v1/auth/logout').flush(null, { status: 204, statusText: 'No Content' });

    expect(service.user()).toBeNull();
  });
});
