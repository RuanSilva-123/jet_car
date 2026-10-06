import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Serviços',
    loadComponent: () => import('./pages/service-list/service-list').then((m) => m.ServiceList),
  },
] satisfies Routes;
