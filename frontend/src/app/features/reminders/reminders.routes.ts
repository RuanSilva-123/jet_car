import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Lembretes de revisão',
    loadComponent: () => import('./pages/reminder-list/reminder-list').then((m) => m.ReminderList),
  },
] satisfies Routes;
