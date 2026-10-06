import { Routes } from '@angular/router';

import { authGuard } from './core/auth/guards/auth.guard';
import { financeGuard } from './core/auth/guards/finance.guard';
import { guestGuard } from './core/auth/guards/guest.guard';
import { masterGuard } from './core/auth/guards/master.guard';
import { AdminLayout } from './layouts/admin-layout/admin-layout';
import { AuthLayout } from './layouts/auth-layout/auth-layout';

export const routes: Routes = [
  // Áreas públicas: login (não existe tela de cadastro) e o orçamento por link
  {
    path: 'login',
    component: AuthLayout,
    canMatch: [guestGuard],
    loadChildren: () => import('./features/auth/auth.routes'),
  },

  // Orçamento aberto pelo cliente (link assinado do WhatsApp): sem login e sem o layout do painel
  {
    path: 'orcamento/:token',
    title: 'Orçamento',
    loadComponent: () => import('./features/public-budget/public-budget').then((m) => m.PublicBudgetPage),
  },

  // Todo o restante exige sessão válida
  {
    path: '',
    component: AdminLayout,
    canMatch: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      { path: 'dashboard', loadChildren: () => import('./features/dashboard/dashboard.routes') },
      { path: 'agenda', loadChildren: () => import('./features/agenda/agenda.routes') },
      { path: 'service-orders', loadChildren: () => import('./features/service-orders/service-orders.routes') },
      {
        path: 'vehicles',
        loadChildren: () => import('./features/service-orders/service-orders.routes').then((m) => m.vehicleRoutes),
      },
      { path: 'customers', loadChildren: () => import('./features/customers/customers.routes') },
      { path: 'services', loadChildren: () => import('./features/services/services.routes') },
      { path: 'parts', loadChildren: () => import('./features/parts/parts.routes') },
      { path: 'reminders', loadChildren: () => import('./features/reminders/reminders.routes') },
      // Financeiro e relatórios: toda a equipe menos o mecânico
      { path: 'finance', canMatch: [financeGuard], loadChildren: () => import('./features/finance/finance.routes') },
      { path: 'reports', canMatch: [financeGuard], loadChildren: () => import('./features/reports/reports.routes') },
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
