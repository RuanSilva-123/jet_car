import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, OnInit, signal, viewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { debounceTime, finalize, Subject } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { centsToInput, maskMoney, moneyToCents } from '../../../../shared/utils/br-format';
import { PAYMENT_METHODS, PaymentMethod } from '../../../service-orders/models/service-order';
import { localDate } from '../../dates';
import { Income, INCOME_CATEGORIES, IncomeCategory, IncomeList, IncomeStatus, PayablesService } from '../../payables.service';

type Field = 'description' | 'category' | 'amount_cents' | 'expected_on' | 'received_at' | 'payment_method';

/**
 * Outras entradas do caixa: dinheiro que não vem de OS (aporte, empréstimo, venda de um bem,
 * prêmio...). Prevista ou já recebida; entra no fluxo de caixa e no saldo.
 */
@Component({
  selector: 'app-income-tab',
  imports: [DatePipe, Icon, Pagination, ConfirmDialog, MoneyPipe],
  templateUrl: './income-tab.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss', '../../pages/bills/bills-shared.scss'],
  styles: `
    .tiles {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }

    .tile {
      display: grid;
      gap: 2px;
      padding: 16px 18px;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      background: var(--color-surface);
      box-shadow: var(--shadow-sm);

      span {
        color: var(--color-text-muted);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }

      strong {
        font-family: var(--font-display);
        font-size: 22px;
        font-variant-numeric: tabular-nums;

        &.is-in {
          color: var(--color-success);
        }
      }

      small {
        color: var(--color-text-muted);
        font-size: 12px;
      }
    }

    .date {
      white-space: nowrap;

      small {
        display: block;
        color: var(--color-text-muted);
        font-size: 12px;
      }

      &.is-late,
      &.is-late small {
        color: var(--color-warning);
      }
    }

    .amount-in {
      color: var(--color-success);
    }

    .when {
      display: flex;

      button {
        flex: 1;
      }
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IncomeTab implements OnInit {
  private readonly payables = inject(PayablesService);
  private readonly toast = inject(ToastService);
  protected readonly isMaster = inject(AuthService).isMaster;

  protected readonly categories = INCOME_CATEGORIES;
  protected readonly methods = PAYMENT_METHODS;
  protected readonly today = localDate(new Date());
  protected readonly statuses: { value: IncomeStatus; label: string }[] = [
    { value: 'all', label: 'Todas' },
    { value: 'pending', label: 'Previstas' },
    { value: 'received', label: 'Recebidas' },
  ];

  protected readonly status = signal<IncomeStatus>('all');
  protected readonly search = signal('');
  protected readonly pageNumber = signal(1);
  protected readonly page = signal<IncomeList | null>(null);
  protected readonly loading = signal(true);

  // Cadastro / edição
  protected readonly formOpen = signal(false);
  protected readonly editing = signal<Income | null>(null);
  protected readonly description = signal('');
  protected readonly category = signal<IncomeCategory>('other');
  protected readonly amount = signal('');
  protected readonly expectedOn = signal(this.today);
  protected readonly receivedNow = signal(true);
  protected readonly receivedAt = signal(this.today);
  protected readonly method = signal<PaymentMethod>('pix');
  protected readonly notes = signal('');
  protected readonly errors = signal<Partial<Record<Field, string>>>({});
  protected readonly saving = signal(false);

  // Recebimento de uma prevista
  protected readonly receiving = signal<Income | null>(null);
  protected readonly receiveDate = signal(this.today);
  protected readonly receiveAmount = signal('');
  protected readonly receiveMethod = signal<PaymentMethod>('pix');
  protected readonly receiveError = signal<string | null>(null);

  protected readonly pendingDelete = signal<Income | null>(null);
  protected readonly pendingUnreceive = signal<Income | null>(null);
  protected readonly working = signal(false);

  protected readonly receivedLocked = computed(() => !!this.editing()?.received_at);

  private readonly search$ = new Subject<string>();
  private readonly formDialog = viewChild.required<ElementRef<HTMLDialogElement>>('formDialog');
  private readonly receiveDialog = viewChild.required<ElementRef<HTMLDialogElement>>('receiveDialog');

  constructor() {
    this.search$.pipe(debounceTime(250), takeUntilDestroyed()).subscribe((term) => {
      this.search.set(term);
      this.pageNumber.set(1);
      this.load();
    });
    effect(() => {
      const dialog = this.formDialog().nativeElement;
      if (this.formOpen() && !dialog.open) dialog.showModal();
      else if (!this.formOpen() && dialog.open) dialog.close();
    });
    effect(() => {
      const dialog = this.receiveDialog().nativeElement;
      if (this.receiving() && !dialog.open) dialog.showModal();
      else if (!this.receiving() && dialog.open) dialog.close();
    });
  }

  ngOnInit(): void {
    this.load();
  }

  protected setStatus(status: IncomeStatus): void {
    this.status.set(status);
    this.pageNumber.set(1);
    this.load();
  }

  protected onSearch(term: string): void {
    this.search$.next(term.trim());
  }

  protected goTo(page: number): void {
    this.pageNumber.set(page);
    this.load();
  }

  protected money(event: Event, target: 'amount' | 'receive'): void {
    const element = event.target as HTMLInputElement;
    element.value = maskMoney(element.value);
    (target === 'amount' ? this.amount : this.receiveAmount).set(element.value);
  }

  // --- cadastro --------------------------------------------------------------------

  protected create(): void {
    this.fill(null);
  }

  protected edit(income: Income): void {
    this.fill(income);
  }

  protected save(): void {
    if (this.saving()) return;

    const current = this.editing();
    const receivedNow = !current && this.receivedNow();
    const errors: Partial<Record<Field, string>> = {};
    if (!this.description().trim()) errors.description = 'Descreva a entrada.';
    if (moneyToCents(this.amount()) <= 0) errors.amount_cents = 'Informe o valor.';
    if (!receivedNow && !this.expectedOn()) errors.expected_on = 'Informe quando deve entrar.';
    if (receivedNow && !this.receivedAt()) errors.received_at = 'Informe quando entrou.';
    this.errors.set(errors);
    if (Object.keys(errors).length) return;

    this.saving.set(true);
    this.payables
      .saveIncome(current?.id ?? null, {
        description: this.description().trim(),
        category: this.category(),
        amount_cents: moneyToCents(this.amount()),
        expected_on: receivedNow ? null : this.expectedOn(),
        notes: this.notes().trim(),
        ...(current ? {} : { received_at: receivedNow ? this.receivedAt() : null, payment_method: receivedNow ? this.method() : null }),
      })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: () => {
          this.formOpen.set(false);
          this.toast.success(current ? 'Entrada atualizada.' : receivedNow ? 'Entrada lançada no caixa.' : 'Entrada prevista lançada.');
          this.load();
        },
        error: (error: unknown) => {
          if (error instanceof HttpErrorResponse && error.status === 422) {
            const raw = (error.error?.errors ?? {}) as Record<string, string[]>;
            this.errors.set(Object.fromEntries(Object.entries(raw).map(([key, messages]) => [key, messages[0]])));
            return;
          }
          this.toast.error('Não foi possível salvar a entrada.');
        },
      });
  }

  protected onFormCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.formOpen.set(false);
  }

  // --- recebimento -----------------------------------------------------------------

  protected openReceive(income: Income): void {
    this.receiveDate.set(this.today);
    this.receiveAmount.set(centsToInput(income.amount_cents));
    this.receiveMethod.set('pix');
    this.receiveError.set(null);
    this.receiving.set(income);
  }

  protected confirmReceive(): void {
    const income = this.receiving();
    if (!income || this.working()) return;
    const cents = moneyToCents(this.receiveAmount());
    if (cents <= 0) {
      this.receiveError.set('Informe o valor que entrou.');
      return;
    }

    this.working.set(true);
    this.payables
      .receiveIncome(income.id, { received_at: this.receiveDate(), payment_method: this.receiveMethod(), amount_cents: cents === income.amount_cents ? null : cents })
      .pipe(finalize(() => this.working.set(false)))
      .subscribe({
        next: () => {
          this.receiving.set(null);
          this.toast.success(`“${income.description}” recebida.`);
          this.load();
        },
        error: (error: unknown) => {
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.receiveError.set(first?.[0] ?? 'Não foi possível registrar.');
        },
      });
  }

  protected onReceiveCancel(event: Event): void {
    event.preventDefault();
    if (!this.working()) this.receiving.set(null);
  }

  protected confirmUnreceive(): void {
    const income = this.pendingUnreceive();
    if (!income) return;
    this.working.set(true);
    this.payables
      .unreceiveIncome(income.id)
      .pipe(finalize(() => this.working.set(false)))
      .subscribe({
        next: () => {
          this.pendingUnreceive.set(null);
          this.toast.success('Recebimento estornado. A entrada voltou para prevista.');
          this.load();
        },
        error: () => {
          this.pendingUnreceive.set(null);
          this.toast.error('Não foi possível estornar.');
        },
      });
  }

  protected confirmDelete(): void {
    const income = this.pendingDelete();
    if (!income) return;
    this.working.set(true);
    this.payables
      .deleteIncome(income.id)
      .pipe(finalize(() => this.working.set(false)))
      .subscribe({
        next: () => {
          this.pendingDelete.set(null);
          this.toast.success('Entrada excluída.');
          this.load();
        },
        error: () => {
          this.pendingDelete.set(null);
          this.toast.error('Não foi possível excluir.');
        },
      });
  }

  private fill(income: Income | null): void {
    this.editing.set(income);
    this.description.set(income?.description ?? '');
    this.category.set(income?.category ?? 'other');
    this.amount.set(income ? centsToInput(income.amount_cents) : '');
    this.expectedOn.set(income?.expected_on ?? this.today);
    this.receivedNow.set(!income);
    this.receivedAt.set(this.today);
    this.method.set('pix');
    this.notes.set(income?.notes ?? '');
    this.errors.set({});
    this.formOpen.set(true);
    setTimeout(() => document.getElementById('income-description')?.focus());
  }

  private load(): void {
    this.loading.set(true);
    this.payables
      .incomes({ status: this.status(), search: this.search(), page: this.pageNumber() })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({ next: (page) => this.page.set(page), error: () => this.toast.error('Não foi possível carregar as entradas.') });
  }
}
