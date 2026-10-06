import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { BarChart, BarDatum } from '../../../../shared/components/bar-chart/bar-chart';
import { Icon } from '../../../../shared/components/icon/icon';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { formatMoney, formatPlate } from '../../../../shared/utils/br-format';
import { formatStock } from '../../../parts/models/part';
import { npsCategory, STATUS_META } from '../../../service-orders/models/service-order';
import { DashboardData, DashboardOrder, DashboardService } from '../../services/dashboard.service';

const BUDGET_DAYS_KEY = 'jetcar.dashboard.budgetDays';

type AttentionTab = 'overdue' | 'budgets' | 'pickup';

/** Eixo do gráfico: "R$ 1,2 mil", "R$ 800". */
function compactMoney(cents: number): string {
  const reais = cents / 100;
  return reais >= 1000 ? `R$ ${(reais / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} mil` : `R$ ${Math.round(reais)}`;
}

/** Visão geral da oficina: o que está em andamento, o que precisa de ação hoje e os números do mês. */
@Component({
  selector: 'app-dashboard',
  imports: [DatePipe, RouterLink, Icon, BarChart, MoneyPipe],
  templateUrl: './dashboard.html',
  styleUrls: ['./dashboard.scss', './dashboard-extras.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Dashboard implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly dashboard = inject(DashboardService);

  protected readonly firstName = computed(() => this.auth.user()?.name.split(' ')[0] ?? '');
  protected readonly statusMeta = STATUS_META;
  protected readonly stock = formatStock;

  protected readonly greeting = (() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Bom dia';
    if (hour < 18) return 'Boa tarde';
    return 'Boa noite';
  })();

  /** "terça-feira, 6 de outubro" */
  protected readonly todayLabel = new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
  protected readonly formatTick = compactMoney;

  protected readonly data = signal<DashboardData | null>(null);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);
  protected readonly budgetDays = signal(this.savedBudgetDays());

  /** Variação do faturamento contra o mesmo período do mês anterior. */
  protected readonly revenueTrend = computed(() => {
    const finance = this.data()?.finance;
    if (!finance || !finance.previous_revenue_cents) return null;
    return Math.round(((finance.revenue_cents - finance.previous_revenue_cents) / finance.previous_revenue_cents) * 100);
  });

  protected readonly isMaster = this.auth.isMaster;
  protected readonly canManageFinance = this.auth.canManageFinance;
  protected readonly npsCategory = npsCategory;

  /** Aviso do backup (só master): falhou, parou ou nunca rodou. */
  protected readonly backupAlert = computed(() => {
    const backup = this.data()?.backup;
    if (!backup) return null;
    if (!backup.configured) return 'Nenhum backup automático encontrado. Confira se o serviço "backup" do Docker está rodando.';
    if (!backup.ok) return `O último backup automático falhou${backup.error ? `: ${backup.error}` : '.'}`;
    if (backup.stale) return 'O backup automático está parado há mais de um dia.';
    return null;
  });

  /** Faixa do NPS: excelente (75+), muito bom (50+), razoável (0+), crítico. */
  protected readonly npsZone = computed(() => {
    const nps = this.data()?.satisfaction.nps;
    if (nps === null || nps === undefined) return null;
    if (nps >= 75) return { label: 'Excelente', tone: 'success' };
    if (nps >= 50) return { label: 'Muito bom', tone: 'success' };
    if (nps >= 0) return { label: 'Razoável', tone: 'warning' };
    return { label: 'Crítico', tone: 'danger' };
  });

  /** Faturamento dos últimos 30 dias no gráfico de colunas. */
  protected readonly revenueChart = computed<BarDatum[]>(() =>
    (this.data()?.finance?.daily ?? []).map((day) => {
      const [, month, date] = day.date.split('-');
      return {
        label: `${date}/${month}`,
        title: new Date(`${day.date}T12:00:00`).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' }),
        value: day.revenue_cents,
        display: formatMoney(day.revenue_cents),
        detail: `${day.orders} ${day.orders === 1 ? 'OS entregue' : 'OS entregues'} · recebido ${formatMoney(day.received_cents)}`,
      };
    }),
  );

  protected readonly revenue30 = computed(() => {
    const daily = this.data()?.finance?.daily ?? [];
    return {
      total: daily.reduce((sum, day) => sum + day.revenue_cents, 0),
      orders: daily.reduce((sum, day) => sum + day.orders, 0),
      received: daily.reduce((sum, day) => sum + day.received_cents, 0),
    };
  });

  /** Mês anterior por extenso, para a comparação do faturamento. */
  protected readonly previousMonth = computed(() => {
    const month = this.data()?.finance?.month;
    if (!month) return '';
    const [year, number] = month.split('-').map(Number);
    return new Date(year, number - 2, 1).toLocaleDateString('pt-BR', { month: 'long' });
  });

  /** OS na oficina por status, com a barra proporcional ao maior. */
  protected readonly workshop = computed(() => {
    const counts = this.data()?.status_counts ?? [];
    const max = Math.max(1, ...counts.map((item) => item.count));
    return counts.map((item) => ({ ...item, meta: STATUS_META[item.status], width: (item.count / max) * 100 }));
  });

  // "Precisa de atenção": abas com as três listas de ação
  private readonly chosenTab = signal<AttentionTab | null>(null);
  protected readonly attentionTabs = computed(() => {
    const d = this.data();
    return [
      { key: 'overdue' as const, label: 'Atrasadas', count: d?.overdue.count ?? 0, tone: 'danger' },
      { key: 'budgets' as const, label: 'Orçamentos sem resposta', count: d?.stale_budgets.count ?? 0, tone: 'warning' },
      { key: 'pickup' as const, label: 'Prontos para retirada', count: d?.ready_for_pickup.count ?? 0, tone: 'success' },
    ];
  });
  /** Aba aberta: a escolhida ou a primeira com pendências. */
  protected readonly attentionTab = computed<AttentionTab>(
    () => this.chosenTab() ?? this.attentionTabs().find((tab) => tab.count > 0)?.key ?? 'overdue',
  );
  protected readonly attentionTotal = computed(() => this.attentionTabs().reduce((sum, tab) => sum + tab.count, 0));

  protected readonly alertsCount = computed(() => {
    const d = this.data();
    return d ? d.low_stock.count + d.reminders_pending : 0;
  });

  ngOnInit(): void {
    this.load();
  }

  protected selectTab(tab: AttentionTab): void {
    this.chosenTab.set(tab);
  }

  protected setBudgetDays(value: number): void {
    this.budgetDays.set(value);
    try {
      localStorage.setItem(BUDGET_DAYS_KEY, String(value));
    } catch {
      // Sem storage (modo privado): só não lembra a escolha
    }
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.dashboard
      .get(this.budgetDays())
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (data) => {
          this.data.set(data);
          this.failed.set(false);
        },
        error: () => this.failed.set(true),
      });
  }

  /** Dias desde uma data (ISO ou YYYY-MM-DD). */
  protected daysSince(date: string | null): number {
    if (!date) return 0;
    const value = date.length === 10 ? new Date(`${date}T12:00:00`) : new Date(date);
    return Math.max(0, Math.floor((Date.now() - value.getTime()) / 86400000));
  }

  protected plate(value: string | null): string {
    return value ? formatPlate(value) : '';
  }

  /** Cobrança do orçamento sem resposta. */
  protected budgetFollowUp(order: DashboardOrder): string {
    const text = `Olá, ${order.customer.split(' ')[0]}! Tudo bem? Passando para saber se conseguiu ver o orçamento da OS #${order.number} (${order.vehicle}), no valor de ${formatMoney(order.total_cents)}. Podemos seguir com o serviço?`;
    return `https://wa.me/55${order.phone}?text=${encodeURIComponent(text)}`;
  }

  /** Aviso de carro pronto. */
  protected pickupNotice(order: DashboardOrder): string {
    const text = `Olá, ${order.customer.split(' ')[0]}! Seu ${order.vehicle}${order.plate ? ` (${formatPlate(order.plate)})` : ''} está pronto para retirada. Estamos te esperando!`;
    return `https://wa.me/55${order.phone}?text=${encodeURIComponent(text)}`;
  }

  private savedBudgetDays(): number {
    try {
      const value = Number(localStorage.getItem(BUDGET_DAYS_KEY));
      return value >= 1 && value <= 60 ? value : 3;
    } catch {
      return 3;
    }
  }
}
