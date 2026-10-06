import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { API_URL, ApiResource, Paginated } from '../../core/http/api';
import { PaymentMethod } from '../service-orders/models/service-order';

export type ExpenseCategory =
  | 'parts'
  | 'rent'
  | 'payroll'
  | 'utilities'
  | 'taxes'
  | 'tools'
  | 'outsourced'
  | 'transport'
  | 'marketing'
  | 'other';

/** Mesmos rótulos do backend (App\Enums\ExpenseCategory). */
export const EXPENSE_CATEGORIES: { value: ExpenseCategory; label: string }[] = [
  { value: 'parts', label: 'Peças e estoque' },
  { value: 'rent', label: 'Aluguel' },
  { value: 'payroll', label: 'Salários e encargos' },
  { value: 'utilities', label: 'Água, luz e internet' },
  { value: 'taxes', label: 'Impostos e taxas' },
  { value: 'tools', label: 'Ferramentas e equipamentos' },
  { value: 'outsourced', label: 'Serviços de terceiros' },
  { value: 'transport', label: 'Combustível e transporte' },
  { value: 'marketing', label: 'Marketing' },
  { value: 'other', label: 'Outros' },
];

export interface SupplierRef {
  id: number;
  name: string;
}

export interface Bill {
  id: number;
  description: string;
  category: ExpenseCategory;
  category_label: string;
  amount_cents: number;
  due_date: string;
  paid_at: string | null;
  payment_method: PaymentMethod | null;
  payment_method_label: string | null;
  paid_by: string | null;
  is_overdue: boolean;
  document_number: string | null;
  notes: string | null;
  installment_number: number | null;
  installment_count: number | null;
  recurring_bill_id: number | null;
  supplier: SupplierRef | null;
}

export type BillStatus = 'open' | 'overdue' | 'paid' | 'all';

export interface BillQuery {
  status: BillStatus;
  month: string;
  search: string;
  category: ExpenseCategory | '';
  supplierId: number | null;
  page: number;
}

export interface BillList extends Paginated<Bill> {
  summary: {
    filtered_cents: number;
    overdue: { count: number; cents: number };
    due_today: { count: number; cents: number };
    open_this_month: { count: number; cents: number };
    paid_this_month: number;
  };
}

export interface BillPayload {
  description: string;
  supplier_id: number | null;
  category: ExpenseCategory;
  amount_cents: number;
  due_date: string;
  document_number: string;
  notes: string;
  /** Só no cadastro. */
  installments?: number;
  paid_at?: string | null;
  payment_method?: PaymentMethod | null;
}

export interface RecurringBill {
  id: number;
  description: string;
  category: ExpenseCategory;
  category_label: string;
  amount_cents: number;
  day_of_month: number;
  starts_on: string;
  ends_on: string | null;
  is_active: boolean;
  notes: string | null;
  supplier: SupplierRef | null;
}

export type RecurringBillPayload = Omit<RecurringBill, 'id' | 'category_label' | 'supplier' | 'notes'> & {
  supplier_id: number | null;
  notes: string;
};

export interface Supplier {
  id: number;
  name: string;
  document: string | null;
  phone: string | null;
  email: string | null;
  contact_name: string | null;
  notes: string | null;
  open_bills_count: number;
  open_bills_cents: number;
}

export type SupplierPayload = Pick<Supplier, 'name'> & {
  document: string;
  phone: string;
  email: string;
  contact_name: string;
  notes: string;
};

export type IncomeCategory = 'owner_contribution' | 'loan' | 'asset_sale' | 'refund' | 'investment' | 'other';

/** Mesmos rótulos do backend (App\Enums\IncomeCategory). */
export const INCOME_CATEGORIES: { value: IncomeCategory; label: string }[] = [
  { value: 'owner_contribution', label: 'Aporte do dono' },
  { value: 'loan', label: 'Empréstimo / financiamento' },
  { value: 'asset_sale', label: 'Venda de bens / sucata' },
  { value: 'refund', label: 'Reembolso / devolução' },
  { value: 'investment', label: 'Rendimentos' },
  { value: 'other', label: 'Outras entradas' },
];

/** Entrada do caixa que não vem de OS (prevista ou já recebida). */
export interface Income {
  id: number;
  description: string;
  category: IncomeCategory;
  category_label: string;
  amount_cents: number;
  expected_on: string;
  received_at: string | null;
  payment_method: PaymentMethod | null;
  payment_method_label: string | null;
  received_by: string | null;
  /** Prevista para antes de hoje e ainda não entrou. */
  is_late: boolean;
  notes: string | null;
}

export type IncomeStatus = 'pending' | 'received' | 'all';

export interface IncomeList extends Paginated<Income> {
  summary: {
    pending: { count: number; cents: number };
    late: { count: number; cents: number };
    received_this_month: number;
  };
}

export interface IncomePayload {
  description: string;
  category: IncomeCategory;
  amount_cents: number;
  expected_on: string | null;
  notes: string;
  /** Só no cadastro: já entrou. */
  received_at?: string | null;
  payment_method?: PaymentMethod | null;
}

export interface CashFlowDay {
  date: string;
  in_cents: number;
  /** Outras entradas previstas para o dia (as atrasadas contam hoje). */
  planned_in_cents: number;
  out_cents: number;
  planned_out_cents: number;
  balance_cents: number;
  /** Dia futuro: saldo projetado com as contas a vencer. */
  projected: boolean;
}

export interface CashFlowSettings {
  opening_balance_cents: number;
  opening_date: string | null;
}

export interface CashFlowMonth {
  month: string;
  opening_balance_cents: number;
  days: CashFlowDay[];
  by_category: { category: ExpenseCategory; label: string; paid_cents: number; open_cents: number }[];
  summary: {
    /** Tudo que entrou: OS + outras entradas. */
    received_cents: number;
    received_orders_cents: number;
    received_other_cents: number;
    to_receive_other_cents: number;
    to_receive_other_count: number;
    paid_cents: number;
    net_cents: number;
    current_balance_cents: number;
    to_pay_cents: number;
    to_pay_count: number;
    overdue_cents: number;
    overdue_count: number;
    receivable_cents: number;
    projected_end_cents: number;
    projected_with_receivables_cents: number;
  };
  settings: CashFlowSettings;
}

/** Contas a pagar, despesas fixas, fornecedores e fluxo de caixa. */
@Injectable({ providedIn: 'root' })
export class PayablesService {
  private readonly http = inject(HttpClient);

  // --- contas a pagar ---------------------------------------------------------------

  bills(query: BillQuery): Observable<BillList> {
    let params = new HttpParams().set('page', query.page).set('status', query.status).set('per_page', 20);
    if (query.month) params = params.set('month', query.month);
    if (query.search) params = params.set('search', query.search);
    if (query.category) params = params.set('category', query.category);
    if (query.supplierId) params = params.set('supplier_id', query.supplierId);
    return this.http.get<BillList>(`${API_URL}/bills`, { params });
  }

  createBill(payload: BillPayload): Observable<Bill[]> {
    return this.http.post<ApiResource<Bill[]>>(`${API_URL}/bills`, payload).pipe(map(({ data }) => data));
  }

  updateBill(id: number, payload: BillPayload): Observable<Bill> {
    return this.http.put<ApiResource<Bill>>(`${API_URL}/bills/${id}`, payload).pipe(map(({ data }) => data));
  }

  deleteBill(id: number): Observable<void> {
    return this.http.delete<void>(`${API_URL}/bills/${id}`);
  }

  payBill(id: number, payment: { paid_at: string; payment_method: PaymentMethod; amount_cents: number | null }): Observable<Bill> {
    return this.http.post<ApiResource<Bill>>(`${API_URL}/bills/${id}/pay`, payment).pipe(map(({ data }) => data));
  }

  unpayBill(id: number): Observable<Bill> {
    return this.http.post<ApiResource<Bill>>(`${API_URL}/bills/${id}/unpay`, {}).pipe(map(({ data }) => data));
  }

  // --- despesas fixas ---------------------------------------------------------------

  recurring(): Observable<{ data: RecurringBill[]; summary: { monthly_cents: number } }> {
    return this.http.get<{ data: RecurringBill[]; summary: { monthly_cents: number } }>(`${API_URL}/recurring-bills`);
  }

  saveRecurring(id: number | null, payload: RecurringBillPayload): Observable<RecurringBill> {
    const request = id
      ? this.http.put<ApiResource<RecurringBill>>(`${API_URL}/recurring-bills/${id}`, payload)
      : this.http.post<ApiResource<RecurringBill>>(`${API_URL}/recurring-bills`, payload);
    return request.pipe(map(({ data }) => data));
  }

  deleteRecurring(id: number): Observable<void> {
    return this.http.delete<void>(`${API_URL}/recurring-bills/${id}`);
  }

  // --- fornecedores -----------------------------------------------------------------

  suppliers(query: { search: string; page: number; perPage?: number }): Observable<Paginated<Supplier>> {
    let params = new HttpParams().set('page', query.page).set('per_page', query.perPage ?? 20);
    if (query.search) params = params.set('search', query.search);
    return this.http.get<Paginated<Supplier>>(`${API_URL}/suppliers`, { params });
  }

  saveSupplier(id: number | null, payload: SupplierPayload): Observable<Supplier> {
    const request = id
      ? this.http.put<ApiResource<Supplier>>(`${API_URL}/suppliers/${id}`, payload)
      : this.http.post<ApiResource<Supplier>>(`${API_URL}/suppliers`, payload);
    return request.pipe(map(({ data }) => data));
  }

  deleteSupplier(id: number): Observable<void> {
    return this.http.delete<void>(`${API_URL}/suppliers/${id}`);
  }

  // --- outras entradas --------------------------------------------------------------

  incomes(query: { status: IncomeStatus; search: string; page: number }): Observable<IncomeList> {
    let params = new HttpParams().set('page', query.page).set('status', query.status).set('per_page', 20);
    if (query.search) params = params.set('search', query.search);
    return this.http.get<IncomeList>(`${API_URL}/incomes`, { params });
  }

  saveIncome(id: number | null, payload: IncomePayload): Observable<Income> {
    const request = id
      ? this.http.put<ApiResource<Income>>(`${API_URL}/incomes/${id}`, payload)
      : this.http.post<ApiResource<Income>>(`${API_URL}/incomes`, payload);
    return request.pipe(map(({ data }) => data));
  }

  receiveIncome(id: number, payment: { received_at: string; payment_method: PaymentMethod; amount_cents: number | null }): Observable<Income> {
    return this.http.post<ApiResource<Income>>(`${API_URL}/incomes/${id}/receive`, payment).pipe(map(({ data }) => data));
  }

  unreceiveIncome(id: number): Observable<Income> {
    return this.http.post<ApiResource<Income>>(`${API_URL}/incomes/${id}/unreceive`, {}).pipe(map(({ data }) => data));
  }

  deleteIncome(id: number): Observable<void> {
    return this.http.delete<void>(`${API_URL}/incomes/${id}`);
  }

  // --- fluxo de caixa ---------------------------------------------------------------

  cashFlow(month: string): Observable<CashFlowMonth> {
    return this.http
      .get<ApiResource<CashFlowMonth>>(`${API_URL}/cash-flow`, { params: new HttpParams().set('month', month) })
      .pipe(map(({ data }) => data));
  }

  cashFlowCsvUrl(month: string): string {
    return `${API_URL}/cash-flow?month=${month}&format=csv`;
  }

  saveOpeningBalance(settings: CashFlowSettings): Observable<CashFlowSettings> {
    return this.http.put<ApiResource<CashFlowSettings>>(`${API_URL}/settings/finance`, settings).pipe(map(({ data }) => data));
  }
}

/** "2026-10" → "outubro de 2026". */
export function monthLabel(month: string): string {
  const [year, number] = month.split('-').map(Number);
  return new Date(year, number - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}

/** Soma meses a "YYYY-MM". */
export function addMonths(month: string, delta: number): string {
  const [year, number] = month.split('-').map(Number);
  const date = new Date(year, number - 1 + delta, 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

export function currentMonth(): string {
  const today = new Date();
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
}
