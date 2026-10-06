import { Routes } from '@angular/router';

import { authGuard } from './core/auth/guards/auth.guard';
import { guestGuard } from './core/auth/guards/guest.guard';
import { masterGuard } from './core/auth/guards/master.guard';
import { AdminLayout } from './layouts/admin-layout/admin-layout';
import { AuthLayout } from './layouts/auth-layout/auth-layout';

export const routes: Routes = [
  // Única área pública: login (não existe tela de cadastro)
  {
    path: 'login',
    component: AuthLayout,
    canMatch: [guestGuard],
    loadChildren: () => import('./features/auth/auth.routes'),
  },

  // Todo o restante exige sessão válida
  {
    path: '',
    component: AdminLayout,
    canMatch: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      { path: 'dashboard', loadChildren: () => import('./features/dashboard/dashboard.routes') },
      { path: 'service-orders', loadChildren: () => import('./features/service-orders/service-orders.routes') },
      {
        path: 'vehicles',
        loadChildren: () => import('./features/service-orders/service-orders.routes').then((m) => m.vehicleRoutes),
      },
      { path: 'customers', loadChildren: () => import('./features/customers/customers.routes') },
      { path: 'services', loadChildren: () => import('./features/services/services.routes') },
      // Gestão de contas: só o master
      { path: 'users', canMatch: [masterGuard], loadChildren: () => import('./features/users/users.routes') },
      {
        path: 'settings/shop',
        title: 'Dados da oficina',
        canMatch: [masterGuard],
        loadComponent: () => import('./features/settings/shop-settings').then((m) => m.ShopSettingsPage),
      },
    ],
  },

  { path: '**', redirectTo: '' },
];
