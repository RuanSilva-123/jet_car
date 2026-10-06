import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { formatMoney, formatPlate } from '../../../../shared/utils/br-format';
import { formatStock } from '../../../parts/models/part';
import { npsCategory, STATUS_META } from '../../../service-orders/models/service-order';
import { DashboardData, DashboardOrder, DashboardService } from '../../services/dashboard.service';

const BUDGET_DAYS_KEY = 'jetcar.dashboard.budgetDays';

/** Visão geral da oficina: o que está em andamento, o que precisa de ação hoje e os números do mês. */
@Component({
  selector: 'app-dashboard',
  imports: [DatePipe, RouterLink, Icon, BrFormatPipe, MoneyPipe],
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

  ngOnInit(): void {
    this.load();
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
