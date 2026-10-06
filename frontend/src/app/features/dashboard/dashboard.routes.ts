import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Dashboard',
    loadComponent: () => import('./pages/dashboard/dashboard').then((m) => m.Dashboard),
  },
] satisfies Routes;
