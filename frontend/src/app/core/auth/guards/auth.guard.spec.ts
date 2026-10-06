import { TestBed } from '@angular/core/testing';
import { CanMatchFn, Route, Router, UrlSegment, UrlTree } from '@angular/router';

import { AuthService } from '../services/auth.service';
import { authGuard } from './auth.guard';
import { guestGuard } from './guest.guard';

type MatchSnapshot = Parameters<CanMatchFn>[2];

describe('guards de autenticação', () => {
  let loggedIn: boolean;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [{ provide: AuthService, useValue: { ensureSession: () => Promise.resolve(loggedIn) } }],
    });
  });

  const runAuthGuard = (path: string) =>
    TestBed.runInInjectionContext(() =>
      authGuard({} as Route, path.split('/').map((segment) => new UrlSegment(segment, {})), {} as MatchSnapshot),
    ) as Promise<boolean | UrlTree>;

  const runGuestGuard = () =>
    TestBed.runInInjectionContext(() => guestGuard({} as Route, [], {} as MatchSnapshot)) as Promise<boolean | UrlTree>;

  const serialize = (tree: boolean | UrlTree) => TestBed.inject(Router).serializeUrl(tree as UrlTree);

  it('authGuard libera quem está logado', async () => {
    loggedIn = true;
    expect(await runAuthGuard('dashboard')).toBe(true);
  });

  it('authGuard manda para o login guardando a página pedida', async () => {
    loggedIn = false;
    expect(serialize(await runAuthGuard('dashboard'))).toBe('/login?returnUrl=%2Fdashboard');
  });

  it('guestGuard libera o login para visitantes', async () => {
    loggedIn = false;
    expect(await runGuestGuard()).toBe(true);
  });

  it('guestGuard manda quem já está logado para o dashboard', async () => {
    loggedIn = true;
    expect(serialize(await runGuestGuard())).toBe('/dashboard');
  });
});
