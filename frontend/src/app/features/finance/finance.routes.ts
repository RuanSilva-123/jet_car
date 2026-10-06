import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Contas a receber',
    loadComponent: () => import('./pages/finance/finance').then((m) => m.FinancePage),
  },
  {
    path: 'bills',
    title: 'Contas a pagar',
    loadComponent: () => import('./pages/bills/bills').then((m) => m.BillsPage),
  },
  {
    path: 'cash-flow',
    title: 'Fluxo de caixa',
    loadComponent: () => import('./pages/cash-flow/cash-flow').then((m) => m.CashFlowPage),
  },
] satisfies Routes;
