import { TestBed } from '@angular/core/testing';
import { CanMatchFn, Route, Router, UrlTree } from '@angular/router';

import { AuthService } from '../services/auth.service';
import { masterGuard } from './master.guard';

type MatchSnapshot = Parameters<CanMatchFn>[2];

describe('masterGuard', () => {
  let session: { loggedIn: boolean; master: boolean };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: AuthService,
          useValue: {
            ensureSession: () => Promise.resolve(session.loggedIn),
            isMaster: () => session.master,
          },
        },
      ],
    });
  });

  const run = () =>
    TestBed.runInInjectionContext(() => masterGuard({} as Route, [], {} as MatchSnapshot)) as Promise<
      boolean | UrlTree
    >;
  const serialize = (tree: boolean | UrlTree) => TestBed.inject(Router).serializeUrl(tree as UrlTree);

  it('libera o master', async () => {
    session = { loggedIn: true, master: true };
    expect(await run()).toBe(true);
  });

  it('manda o admin comum para o dashboard', async () => {
    session = { loggedIn: true, master: false };
    expect(serialize(await run())).toBe('/dashboard');
  });

  it('manda quem não está logado para o login', async () => {
    session = { loggedIn: false, master: false };
    expect(serialize(await run())).toBe('/login');
  });
});
