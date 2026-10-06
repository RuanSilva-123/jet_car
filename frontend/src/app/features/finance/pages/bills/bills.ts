import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { debounceTime, finalize, Subject } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { BillDialog } from '../../components/bill-dialog/bill-dialog';
import { PayBillDialog } from '../../components/pay-bill-dialog/pay-bill-dialog';
import { RecurringTab } from '../../components/recurring-tab/recurring-tab';
import { SupplierTab } from '../../components/supplier-tab/supplier-tab';
import { localDate } from '../../dates';
import { Bill, BillList, BillStatus, EXPENSE_CATEGORIES, ExpenseCategory, PayablesService, Supplier, SupplierRef } from '../../payables.service';

type Tab = 'bills' | 'recurring' | 'suppliers';

const STATUSES: { value: BillStatus; label: string }[] = [
  { value: 'open', label: 'Em aberto' },
  { value: 'overdue', label: 'Vencidas' },
  { value: 'paid', label: 'Pagas' },
  { value: 'all', label: 'Todas' },
];

/** Contas a pagar: contas (com parcelas), despesas fixas mensais e fornecedores. */
@Component({
  selector: 'app-bills',
  imports: [RouterLink, DatePipe, Icon, Pagination, ConfirmDialog, MoneyPipe, BillDialog, PayBillDialog, RecurringTab, SupplierTab],
  templateUrl: './bills.html',
  styleUrls: ['./bills-shared.scss', './bills.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BillsPage implements OnInit {
  private readonly payables = inject(PayablesService);
  private readonly toast = inject(ToastService);
  protected readonly isMaster = inject(AuthService).isMaster;

  /** ?tab=recurring|suppliers e ?status=overdue (links do painel). */
  readonly tabParam = input<string | undefined>(undefined, { alias: 'tab' });
  readonly statusParam = input<string | undefined>(undefined, { alias: 'status' });

  protected readonly tab = signal<Tab>('bills');
  protected readonly statuses = STATUSES;
  protected readonly categories = EXPENSE_CATEGORIES;

  protected readonly status = signal<BillStatus>('open');
  protected readonly month = signal('');
  protected readonly category = signal<ExpenseCategory | ''>('');
  protected readonly search = signal('');
  protected readonly supplier = signal<SupplierRef | null>(null);
  protected readonly pageNumber = signal(1);

  protected readonly page = signal<BillList | null>(null);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);
  protected readonly suppliers = signal<SupplierRef[]>([]);

  protected readonly billDialogOpen = signal(false);
  protected readonly editingBill = signal<Bill | null>(null);
  protected readonly payingBill = signal<Bill | null>(null);
  protected readonly pendingDelete = signal<Bill | null>(null);
  protected readonly pendingUnpay = signal<Bill | null>(null);
  protected readonly working = signal(false);

  protected readonly today = localDate(new Date());
  protected readonly hasFilters = computed(() => !!(this.month() || this.category() || this.search() || this.supplier()));

  private readonly search$ = new Subject<string>();

  constructor() {
    this.search$.pipe(debounceTime(250), takeUntilDestroyed()).subscribe((term) => {
      this.search.set(term);
      this.reload();
    });
  }

  ngOnInit(): void {
    const tab = this.tabParam();
    if (tab === 'recurring' || tab === 'suppliers') this.tab.set(tab);
    const status = this.statusParam();
    if (STATUSES.some((item) => item.value === status)) this.status.set(status as BillStatus);
    this.load();
    this.loadSuppliers();
  }

  protected setTab(tab: Tab): void {
    this.tab.set(tab);
    if (tab === 'bills') this.load();
  }

  protected setStatus(status: BillStatus): void {
    this.status.set(status);
    this.reload();
  }

  protected setMonth(value: string): void {
    this.month.set(value);
    this.reload();
  }

  protected setCategory(value: string): void {
    this.category.set(value as ExpenseCategory | '');
    this.reload();
  }

  protected onSearch(term: string): void {
    this.search$.next(term.trim());
  }

  protected clearFilters(): void {
    this.month.set('');
    this.category.set('');
    this.search.set('');
    this.supplier.set(null);
    this.reload();
  }

  /** Clique no "em aberto" de um fornecedor. */
  protected showSupplierBills(supplier: Supplier): void {
    this.supplier.set({ id: supplier.id, name: supplier.name });
    this.status.set('open');
    this.tab.set('bills');
    this.reload();
  }

  protected goTo(page: number): void {
    this.pageNumber.set(page);
    this.load();
  }

  protected newBill(): void {
    this.editingBill.set(null);
    this.billDialogOpen.set(true);
  }

  protected editBill(bill: Bill): void {
    this.editingBill.set(bill);
    this.billDialogOpen.set(true);
  }

  protected onBillSaved(message: string): void {
    this.billDialogOpen.set(false);
    this.toast.success(message);
    this.load();
  }

  protected onPaid(bill: Bill): void {
    this.payingBill.set(null);
    this.toast.success(`“${bill.description}” paga.`);
    this.load();
  }

  protected confirmDelete(): void {
    const bill = this.pendingDelete();
    if (!bill) return;
    this.working.set(true);
    this.payables
      .deleteBill(bill.id)
      .pipe(finalize(() => this.working.set(false)))
      .subscribe({
        next: () => {
          this.pendingDelete.set(null);
          this.toast.success('Conta excluída.');
          this.load();
        },
        error: () => {
          this.pendingDelete.set(null);
          this.toast.error('Não foi possível excluir a conta.');
        },
      });
  }

  protected confirmUnpay(): void {
    const bill = this.pendingUnpay();
    if (!bill) return;
    this.working.set(true);
    this.payables
      .unpayBill(bill.id)
      .pipe(finalize(() => this.working.set(false)))
      .subscribe({
        next: () => {
          this.pendingUnpay.set(null);
          this.toast.success('Pagamento estornado. A conta voltou para em aberto.');
          this.load();
        },
        error: () => {
          this.pendingUnpay.set(null);
          this.toast.error('Não foi possível estornar.');
        },
      });
  }

  protected loadSuppliers(): void {
    this.payables.suppliers({ search: '', page: 1, perPage: 100 }).subscribe({
      next: (page) => this.suppliers.set(page.data.map(({ id, name }) => ({ id, name }))),
      error: () => this.suppliers.set([]),
    });
  }

  protected reload(): void {
    this.pageNumber.set(1);
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.payables
      .bills({
        status: this.status(),
        month: this.month(),
        search: this.search(),
        category: this.category(),
        supplierId: this.supplier()?.id ?? null,
        page: this.pageNumber(),
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (page) => this.page.set(page),
        error: () => this.failed.set(true),
      });
  }
}
