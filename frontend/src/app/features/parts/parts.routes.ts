import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Estoque de peças',
    loadComponent: () => import('./pages/part-list/part-list').then((m) => m.PartList),
  },
] satisfies Routes;
