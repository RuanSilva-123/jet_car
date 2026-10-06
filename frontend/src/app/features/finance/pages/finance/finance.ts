import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, input, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, map, of, switchMap, tap } from 'rxjs';

import { Icon } from '../../../../shared/components/icon/icon';
import { IncomeTab } from '../../components/income-tab/income-tab';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { StatusBadge } from '../../../service-orders/components/status-badge/status-badge';
import { PAYMENT_METHODS, PaymentMethod } from '../../../service-orders/models/service-order';
import { presetRange } from '../../dates';
import { FinanceService, PaymentsPage, ReceivablesPage, ReceivableScope } from '../../finance.service';

/**
 * Financeiro: contas a receber (OS aprovadas com saldo em aberto) e recebimentos do período.
 * Registrar o pagamento é feito na própria OS.
 */
@Component({
  selector: 'app-finance',
  imports: [RouterLink, DatePipe, Icon, Pagination, BrFormatPipe, MoneyPipe, StatusBadge, IncomeTab],
  templateUrl: './finance.html',
  styleUrls: ['../../../services/pages/service-list/service-list.scss', './finance.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FinancePage implements OnInit {
  private readonly finance = inject(FinanceService);
  private readonly destroyRef = inject(DestroyRef);

  /** ?tab=incomes (link do fluxo de caixa). */
  readonly tabParam = input<string | undefined>(undefined, { alias: 'tab' });
  protected readonly tab = signal<'receivables' | 'payments' | 'incomes'>('receivables');
  protected readonly methods = PAYMENT_METHODS;

  // A receber
  protected readonly search = signal('');
  protected readonly scope = signal<ReceivableScope>('all');
  protected readonly receivablesPage = signal(1);
  protected readonly receivables = signal<ReceivablesPage | null>(null);
  protected readonly loadingReceivables = signal(true);
  protected readonly scopes: { value: ReceivableScope; label: string; hint: string }[] = [
    { value: 'all', label: 'Tudo', hint: 'Todas as OS com saldo' },
    { value: 'delivered', label: 'Já entregues', hint: 'Carro saiu sem quitar: cobrar' },
    { value: 'in_service', label: 'Na oficina', hint: 'Recebe na entrega' },
  ];

  // Recebimentos
  protected readonly from = signal(presetRange('month').from);
  protected readonly to = signal(presetRange('month').to);
  protected readonly method = signal<PaymentMethod | 'all'>('all');
  protected readonly paymentsPage = signal(1);
  protected readonly payments = signal<PaymentsPage | null>(null);
  protected readonly loadingPayments = signal(false);

  protected readonly failed = signal(false);

  private readonly receivablesQuery = computed(() => ({ search: this.search().trim(), scope: this.scope(), page: this.receivablesPage() }));
  private readonly paymentsQuery = computed(() => ({ from: this.from(), to: this.to(), method: this.method(), page: this.paymentsPage() }));

  ngOnInit(): void {
    if (this.tabParam() === 'incomes') this.tab.set('incomes');
  }

  constructor() {
    toObservable(this.receivablesQuery)
      .pipe(
        debounceTime(250),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        tap(() => this.loadingReceivables.set(true)),
        switchMap((query) => this.finance.receivables(query).pipe(map((page) => page), catchError(() => of(null)))),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((page) => {
        this.loadingReceivables.set(false);
        this.failed.set(page === null);
        if (page) this.receivables.set(page);
      });

    toObservable(this.paymentsQuery)
      .pipe(
        debounceTime(250),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        tap(() => this.loadingPayments.set(true)),
        switchMap((query) => (query.from && query.to ? this.finance.payments(query).pipe(catchError(() => of(null))) : of(null))),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((page) => {
        this.loadingPayments.set(false);
        if (page) this.payments.set(page);
      });
  }

  protected setScope(scope: ReceivableScope): void {
    this.scope.set(scope);
    this.receivablesPage.set(1);
  }

  protected onSearch(value: string): void {
    this.search.set(value);
    this.receivablesPage.set(1);
  }

  protected setPreset(preset: 'month' | 'last-month' | 'last-30' | 'year'): void {
    const range = presetRange(preset);
    this.from.set(range.from);
    this.to.set(range.to);
    this.paymentsPage.set(1);
  }

  protected setRange(field: 'from' | 'to', value: string): void {
    this[field].set(value);
    this.paymentsPage.set(1);
  }

  protected setMethod(value: string): void {
    this.method.set(value as PaymentMethod | 'all');
    this.paymentsPage.set(1);
  }

  protected goToReceivables(page: number): void {
    this.receivablesPage.set(page);
  }

  protected goToPayments(page: number): void {
    this.paymentsPage.set(page);
  }

  /** Dias desde a entrega (para cobrança). */
  protected daysSince(date: string | null): number | null {
    return date ? Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 86400000)) : null;
  }

  /** Parte de cada forma de pagamento no total (barra proporcional). */
  protected share(total: number): number {
    const sum = this.payments()?.summary.total_cents ?? 0;
    return sum ? Math.round((total / sum) * 100) : 0;
  }
}
