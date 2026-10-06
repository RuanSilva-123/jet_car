import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Usuários',
    loadComponent: () => import('./pages/user-list/user-list').then((m) => m.UserList),
  },
  {
    path: 'new',
    title: 'Novo usuário',
    loadComponent: () => import('./pages/user-form/user-form').then((m) => m.UserForm),
  },
  {
    path: ':id/edit',
    title: 'Editar usuário',
    loadComponent: () => import('./pages/user-form/user-form').then((m) => m.UserForm),
  },
] satisfies Routes;
