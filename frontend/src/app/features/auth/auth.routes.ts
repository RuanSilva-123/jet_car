import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Entrar',
    loadComponent: () => import('./pages/login/login').then((m) => m.Login),
  },
] satisfies Routes;
