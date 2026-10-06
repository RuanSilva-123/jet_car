import { inject } from '@angular/core';
import { CanMatchFn, Router } from '@angular/router';

import { AuthService } from '../services/auth.service';

/**
 * Financeiro (contas a receber, relatórios): toda a equipe menos o mecânico.
 * Só UX: a API também recusa (gate manage-finance → 403).
 */
export const financeGuard: CanMatchFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!(await auth.ensureSession())) {
    return router.createUrlTree(['/login']);
  }

  return auth.canManageFinance() ? true : router.createUrlTree(['/dashboard']);
};
