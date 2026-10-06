import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Contas a receber',
    loadComponent: () => import('./pages/finance/finance').then((m) => m.FinancePage),
  },
] satisfies Routes;
