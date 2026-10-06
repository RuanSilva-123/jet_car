import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { API_URL } from '../../http/api';
import { AuthService } from '../services/auth.service';

/** Requisições cujo 401 é esperado e tratado por quem chamou. */
const IGNORED_URLS = [`${API_URL}/auth/me`, `${API_URL}/auth/login`];

/**
 * - Garante respostas JSON do Laravel (nunca redirect HTML).
 * - 401 (sessão expirada) ou 419 (CSRF expirado): limpa o estado e volta para o login.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const request = req.clone({
    withCredentials: true,
    setHeaders: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  });

  return next(request).pipe(
    catchError((error: unknown) => {
      const sessionLost =
        error instanceof HttpErrorResponse && (error.status === 401 || error.status === 419);

      if (sessionLost && !IGNORED_URLS.includes(req.url)) {
        auth.clearSession();
        router.navigate(['/login'], { queryParams: { returnUrl: router.url } });
      }

      return throwError(() => error);
    }),
  );
};
