import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Clientes',
    loadComponent: () => import('./pages/customer-list/customer-list').then((m) => m.CustomerList),
  },
  {
    path: 'new',
    title: 'Novo cliente',
    loadComponent: () => import('./pages/customer-form/customer-form').then((m) => m.CustomerForm),
  },
  {
    path: ':id/edit',
    title: 'Editar cliente',
    loadComponent: () => import('./pages/customer-form/customer-form').then((m) => m.CustomerForm),
  },
] satisfies Routes;
