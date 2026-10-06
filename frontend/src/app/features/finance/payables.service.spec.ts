import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { addMonths, monthLabel, PayablesService } from './payables.service';

describe('PayablesService', () => {
  let api: PayablesService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(PayablesService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('só envia os filtros preenchidos', () => {
    api.bills({ status: 'open', month: '', search: '', category: '', supplierId: null, page: 1 }).subscribe();
    const plain = http.expectOne((r) => r.url === '/api/v1/bills');
    expect(plain.request.params.keys().sort()).toEqual(['page', 'per_page', 'status']);

    api.bills({ status: 'paid', month: '2026-10', search: 'nota', category: 'parts', supplierId: 3, page: 2 }).subscribe();
    const full = http.expectOne((r) => r.url === '/api/v1/bills' && r.params.get('page') === '2');
    expect(full.request.params.get('status')).toBe('paid');
    expect(full.request.params.get('month')).toBe('2026-10');
    expect(full.request.params.get('category')).toBe('parts');
    expect(full.request.params.get('supplier_id')).toBe('3');
    expect(full.request.params.get('search')).toBe('nota');
  });

  it('paga, estorna e lança contas', () => {
    api.payBill(9, { paid_at: '2026-10-05', payment_method: 'pix', amount_cents: null }).subscribe();
    const pay = http.expectOne('/api/v1/bills/9/pay');
    expect(pay.request.method).toBe('POST');
    expect(pay.request.body).toEqual({ paid_at: '2026-10-05', payment_method: 'pix', amount_cents: null });

    api.unpayBill(9).subscribe();
    expect(http.expectOne('/api/v1/bills/9/unpay').request.method).toBe('POST');

    let created = 0;
    api
      .createBill({ description: 'Aluguel', supplier_id: null, category: 'rent', amount_cents: 300000, due_date: '2026-10-10', document_number: '', notes: '', installments: 3 })
      .subscribe((bills) => (created = bills.length));
    http.expectOne('/api/v1/bills').flush({ data: [{ id: 1 }, { id: 2 }, { id: 3 }] });
    expect(created).toBe(3);
  });

  it('cria ou atualiza fornecedor e despesa fixa conforme o id', () => {
    const supplier = { name: 'Auto Peças', document: '', phone: '', email: '', contact_name: '', notes: '' };
    api.saveSupplier(null, supplier).subscribe();
    expect(http.expectOne('/api/v1/suppliers').request.method).toBe('POST');
    api.saveSupplier(4, supplier).subscribe();
    expect(http.expectOne('/api/v1/suppliers/4').request.method).toBe('PUT');

    const recurring = {
      description: 'Internet',
      category: 'utilities' as const,
      supplier_id: null,
      amount_cents: 12000,
      day_of_month: 10,
      starts_on: '2026-10-01',
      ends_on: null,
      is_active: true,
      notes: '',
    };
    api.saveRecurring(null, recurring).subscribe();
    expect(http.expectOne('/api/v1/recurring-bills').request.method).toBe('POST');
    api.saveRecurring(2, recurring).subscribe();
    expect(http.expectOne('/api/v1/recurring-bills/2').request.method).toBe('PUT');
  });

  it('busca o fluxo de caixa do mês e monta o link do CSV', () => {
    api.cashFlow('2026-10').subscribe();
    expect(http.expectOne((r) => r.url === '/api/v1/cash-flow').request.params.get('month')).toBe('2026-10');
    expect(api.cashFlowCsvUrl('2026-10')).toBe('/api/v1/cash-flow?month=2026-10&format=csv');
  });
});

describe('meses do fluxo de caixa', () => {
  it('avança e volta virando o ano', () => {
    expect(addMonths('2026-12', 1)).toBe('2027-01');
    expect(addMonths('2026-01', -1)).toBe('2025-12');
    expect(addMonths('2026-10', 0)).toBe('2026-10');
  });

  it('escreve o mês por extenso', () => {
    expect(monthLabel('2026-10')).toBe('outubro de 2026');
  });
});
