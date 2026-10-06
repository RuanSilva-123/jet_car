import { inject } from '@angular/core';
import { CanMatchFn, Router } from '@angular/router';

import { AuthService } from '../services/auth.service';

/**
 * Áreas exclusivas do usuário master (ex.: gestão de usuários).
 * Como o authGuard, é só UX: a API também recusa (UserPolicy → 403).
 */
export const masterGuard: CanMatchFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!(await auth.ensureSession())) {
    return router.createUrlTree(['/login']);
  }

  return auth.isMaster() ? true : router.createUrlTree(['/dashboard']);
};
