import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Agenda',
    loadComponent: () => import('./pages/agenda/agenda').then((m) => m.AgendaPage),
  },
] satisfies Routes;
