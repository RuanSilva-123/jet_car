import { inject } from '@angular/core';
import { CanMatchFn, Router } from '@angular/router';

import { AuthService } from '../services/auth.service';

/** Telas públicas (login): quem já está logado vai direto para o dashboard. */
export const guestGuard: CanMatchFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return (await auth.ensureSession()) ? router.createUrlTree(['/dashboard']) : true;
};
