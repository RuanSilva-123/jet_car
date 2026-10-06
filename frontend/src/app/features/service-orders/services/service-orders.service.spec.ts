import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { FLOW_STEPS, flowStep, nextStep, ServiceOrderStatus, STATUS_ORDER } from '../models/service-order';
import { ServiceOrdersService } from './service-orders.service';

describe('ServiceOrdersService', () => {
  let api: ServiceOrdersService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(ServiceOrdersService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lista "em aberto" por padrão e não envia status quando é "todas"', () => {
    api.list({ search: '', status: 'active', page: 1, perPage: 15 }).subscribe();
    expect(http.expectOne((r) => r.url === '/api/v1/service-orders').request.params.get('status')).toBe('active');

    api.list({ search: 'ruan', status: 'all', page: 2, perPage: 15, vehicleId: 7 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/service-orders' && r.params.get('page') === '2');
    expect(req.request.params.has('status')).toBe(false);
    expect(req.request.params.get('vehicle_id')).toBe('7');
    expect(req.request.params.get('search')).toBe('ruan');
  });

  it('adiciona e remove serviços e peças do diagnóstico', () => {
    api.addItem(5, 12, 'dianteiro').subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/items').request.body).toEqual({ labor_service_id: 12, notes: 'dianteiro' });

    api.removeItem(5, 9).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/items/9').request.method).toBe('DELETE');

    api.addPart(5, { name: 'Bieleta', part_number: '', quantity: 2 }).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/parts').request.body).toEqual({ name: 'Bieleta', part_number: '', quantity: 2 });

    api.removePart(5, 3).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/parts/3').request.method).toBe('DELETE');
  });

  it('usa os endpoints de status, checklist e comentário', () => {
    api.changeStatus(5, 'waiting_parts', 'sem peça').subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/status').request.body).toEqual({ status: 'waiting_parts', note: 'sem peça' });

    api.setItemDone(5, 9, true).subscribe();
    const toggle = http.expectOne('/api/v1/service-orders/5/items/9');
    expect(toggle.request.method).toBe('PATCH');
    expect(toggle.request.body).toEqual({ is_done: true });

    api.addNote(5, 'ok').subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/notes').request.body).toEqual({ note: 'ok' });

    api.vehicleHistory(3).subscribe();
    http.expectOne('/api/v1/vehicles/3/history');

    api.approveBudget(5, 'WhatsApp').subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/budget/approve').request.body).toEqual({ note: 'WhatsApp' });

    api.rejectBudget(5, '', true).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/budget/reject').request.body).toEqual({ note: '', cancel: true });

    expect(api.pdfUrl(5, 'budget')).toBe('/api/v1/service-orders/5/pdf/budget');
    expect(api.pdfUrl(5, 'report', true)).toBe('/api/v1/service-orders/5/pdf/report?download=1');
    expect(api.pdfUrl(5, 'inspection')).toBe('/api/v1/service-orders/5/pdf/inspection');
  });

  it('filtra os serviços do mecânico logado e atribui responsável', () => {
    api.list({ search: '', status: 'active', page: 1, perPage: 15, mechanicId: 'me' }).subscribe();
    expect(http.expectOne((r) => r.url === '/api/v1/service-orders').request.params.get('mechanic_id')).toBe('me');

    api.assignMechanic(5, 9, 4).subscribe();
    const assign = http.expectOne('/api/v1/service-orders/5/items/9/mechanic');
    expect(assign.request.method).toBe('PUT');
    expect(assign.request.body).toEqual({ mechanic_id: 4 });

    api.assignMechanic(5, 9, null).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/items/9/mechanic').request.body).toEqual({ mechanic_id: null });
  });

  it('registra e remove pagamentos e adiciona peça do estoque', () => {
    api.addPayment(5, { method: 'credit_card', amount_cents: 15000, installments: 3, paid_at: '2026-10-06', notes: '' }).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/payments').request.body).toEqual({
      method: 'credit_card',
      amount_cents: 15000,
      installments: 3,
      paid_at: '2026-10-06',
      notes: '',
    });

    api.removePayment(5, 2).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/payments/2').request.method).toBe('DELETE');

    api.addPart(5, { part_id: 8, name: 'Filtro', part_number: 'F1', quantity: 1 }).subscribe();
    expect(http.expectOne('/api/v1/service-orders/5/parts').request.body.part_id).toBe(8);
  });
});

describe('próximo passo da OS', () => {
  const item = { id: 1, labor_service_id: 1, name: 'Troca de óleo', notes: null, price_cents: null, is_done: false, done_at: null, done_by: null, mechanic_id: null, mechanic: null };
  const order = (status: ServiceOrderStatus, budget_approved_at: string | null = null, lines = 1, unpriced_count = 0) => ({
    status,
    budget_approved_at,
    items: Array.from({ length: lines }, () => item),
    parts: [],
    unpriced_count,
  });

  it('aberta: diagnóstico, depois montar orçamento, depois enviar', () => {
    expect(nextStep(order('open', null, 0)).kind).toBe('diagnose');
    expect(nextStep(order('open', null, 2, 1)).kind).toBe('edit-budget');
    expect(nextStep(order('open', null, 2, 0)).kind).toBe('send-budget');
  });

  it('aguardando aprovação: registrar aprovação', () => {
    expect(nextStep(order('waiting_approval')).kind).toBe('approve-budget');
  });

  it('execução segue para concluir e entregar', () => {
    expect(nextStep(order('in_progress', 'x'))).toMatchObject({ kind: 'status', status: 'completed' });
    expect(nextStep(order('completed', 'x'))).toMatchObject({ kind: 'status', status: 'delivered' });
  });

  it('reabrir volta para aberta se não havia aprovação', () => {
    expect(nextStep(order('canceled'))).toMatchObject({ status: 'open', label: 'Reabrir OS' });
    expect(nextStep(order('delivered', 'x'))).toMatchObject({ status: 'in_progress', label: 'Reabrir OS' });
  });

  it('nenhum próximo passo de status repete o status atual', () => {
    for (const status of STATUS_ORDER) {
      const step = nextStep(order(status, 'x'));
      if (step.kind === 'status') expect(step.status).not.toBe(status);
    }
  });

  it('etapas do fluxo', () => {
    expect(flowStep(order('open', null, 0))).toBe(1);
    expect(flowStep(order('open'))).toBe(2);
    expect(flowStep(order('waiting_approval'))).toBe(3);
    expect(flowStep(order('waiting_parts', 'x'))).toBe(4);
    expect(flowStep(order('completed', 'x'))).toBe(5);
    expect(flowStep(order('delivered', 'x'))).toBe(FLOW_STEPS.length);
    expect(flowStep(order('canceled'))).toBeNull();
  });
});
