import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { LineChart, LinePoint } from '../../../../shared/components/line-chart/line-chart';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { formatMoney } from '../../../../shared/utils/br-format';
import { OpeningBalanceDialog } from '../../components/opening-balance-dialog/opening-balance-dialog';
import { addMonths, CashFlowMonth, CashFlowSettings, currentMonth, monthLabel, PayablesService } from '../../payables.service';

/** Valor curto para o eixo do gráfico: "R$ 12 mil", "−R$ 800". */
function compactMoney(cents: number): string {
  const reais = cents / 100;
  const sign = reais < 0 ? '−' : '';
  const abs = Math.abs(reais);
  return abs >= 1000 ? `${sign}R$ ${(abs / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} mil` : `${sign}R$ ${Math.round(abs)}`;
}

/**
 * Fluxo de caixa do mês: o que entrou (pagamentos das OS), o que saiu (contas pagas),
 * o que ainda vence e o saldo projetado até o fim do mês.
 */
@Component({
  selector: 'app-cash-flow',
  imports: [RouterLink, DatePipe, Icon, MoneyPipe, LineChart, OpeningBalanceDialog],
  templateUrl: './cash-flow.html',
  styleUrl: './cash-flow.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CashFlowPage implements OnInit {
  private readonly payables = inject(PayablesService);
  private readonly toast = inject(ToastService);
  protected readonly isMaster = inject(AuthService).isMaster;

  protected readonly month = signal(currentMonth());
  protected readonly data = signal<CashFlowMonth | null>(null);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);
  protected readonly openingOpen = signal(false);
  /** Tabela: só dias com movimento (padrão) ou todos. */
  protected readonly allDays = signal(false);

  protected readonly label = computed(() => monthLabel(this.month()));
  protected readonly isCurrent = computed(() => this.month() === currentMonth());
  protected readonly isFuture = computed(() => this.month() > currentMonth());
  protected readonly csvUrl = computed(() => this.payables.cashFlowCsvUrl(this.month()));
  protected readonly formatTick = compactMoney;

  protected readonly points = computed<LinePoint[]>(() =>
    (this.data()?.days ?? []).map((day) => {
      const date = new Date(`${day.date}T12:00:00`);
      const moves = [
        day.in_cents ? `entrou ${formatMoney(day.in_cents)}` : '',
        day.out_cents ? `saiu ${formatMoney(day.out_cents)}` : '',
        day.planned_out_cents ? `a pagar ${formatMoney(day.planned_out_cents)}` : '',
      ].filter(Boolean);
      return {
        label: String(date.getDate()).padStart(2, '0'),
        title: date.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' }),
        value: day.balance_cents,
        display: formatMoney(day.balance_cents),
        detail: moves.join(' · ') || undefined,
        projected: day.projected,
      };
    }),
  );

  protected readonly rows = computed(() => {
    const days = this.data()?.days ?? [];
    return this.allDays() ? days : days.filter((day) => day.in_cents || day.out_cents || day.planned_out_cents);
  });

  /** Barras das categorias proporcionais à maior. */
  protected readonly categoryMax = computed(() =>
    Math.max(1, ...(this.data()?.by_category ?? []).map((row) => row.paid_cents + row.open_cents)),
  );

  ngOnInit(): void {
    this.load();
  }

  protected go(delta: number): void {
    this.month.set(addMonths(this.month(), delta));
    this.load();
  }

  protected goToday(): void {
    this.month.set(currentMonth());
    this.load();
  }

  protected onOpeningSaved(settings: CashFlowSettings): void {
    this.openingOpen.set(false);
    this.toast.success(`Saldo inicial de ${formatMoney(settings.opening_balance_cents)} salvo.`);
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.payables
      .cashFlow(this.month())
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (data) => this.data.set(data),
        error: () => this.failed.set(true),
      });
  }
}
