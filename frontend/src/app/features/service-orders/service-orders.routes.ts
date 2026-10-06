import { Routes } from '@angular/router';

export default [
  {
    path: '',
    title: 'Ordens de serviço',
    loadComponent: () => import('./pages/order-list/order-list').then((m) => m.OrderList),
  },
  {
    path: 'new',
    title: 'Nova OS',
    loadComponent: () => import('./pages/order-form/order-form').then((m) => m.OrderForm),
  },
  {
    path: ':id',
    title: 'Ordem de serviço',
    loadComponent: () => import('./pages/order-detail/order-detail').then((m) => m.OrderDetail),
  },
  {
    path: ':id/budget',
    title: 'Montar orçamento',
    loadComponent: () => import('./pages/order-budget/order-budget').then((m) => m.OrderBudget),
  },
  {
    path: ':id/inspection',
    title: 'Vistoria de entrada',
    loadComponent: () => import('./pages/order-inspection/order-inspection').then((m) => m.OrderInspection),
  },
  {
    path: ':id/edit',
    title: 'Dados de entrada',
    loadComponent: () => import('./pages/order-form/order-form').then((m) => m.OrderForm),
  },
] satisfies Routes;

/** /vehicles/:id/history */
export const vehicleRoutes: Routes = [
  {
    path: ':id/history',
    title: 'Histórico do veículo',
    loadComponent: () => import('./pages/vehicle-history/vehicle-history').then((m) => m.VehicleHistory),
  },
];
