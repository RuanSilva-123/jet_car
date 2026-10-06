import { inject } from '@angular/core';
import { CanMatchFn, Router } from '@angular/router';

import { AuthService } from '../services/auth.service';

/**
 * Libera a área administrativa apenas com sessão válida.
 * Por ser canMatch, o código das telas protegidas nem é baixado por quem não está logado.
 */
export const authGuard: CanMatchFn = async (_route, segments) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (await auth.ensureSession()) {
    return true;
  }

  const returnUrl = '/' + segments.map((segment) => segment.path).join('/');

  return router.createUrlTree(['/login'], {
    queryParams: returnUrl === '/' ? {} : { returnUrl },
  });
};
