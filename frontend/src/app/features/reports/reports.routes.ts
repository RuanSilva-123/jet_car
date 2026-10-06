import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Relatórios',
    loadComponent: () => import('./pages/reports/reports').then((m) => m.ReportsPage),
  },
] satisfies Routes;
