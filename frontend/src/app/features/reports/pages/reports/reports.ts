import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, input, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { catchError, debounceTime, distinctUntilChanged, of, switchMap, tap } from 'rxjs';

import { BarChart, BarDatum } from '../../../../shared/components/bar-chart/bar-chart';
import { Icon, IconName } from '../../../../shared/components/icon/icon';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { formatMoney, formatPhone } from '../../../../shared/utils/br-format';
import { localDate, presetRange } from '../../../finance/dates';
import { ColumnType, ReportKey, ReportQuery, ReportResult, ReportsService } from '../../reports.service';

const TABS: { key: ReportKey; label: string; icon: IconName; description: string }[] = [
  { key: 'revenue', label: 'Faturamento', icon: 'trending-up', description: 'OS entregues no período, por dia ou mês, com ticket médio e o que foi recebido.' },
  { key: 'services', label: 'Serviços mais vendidos', icon: 'wrench', description: 'Serviços feitos nas OS entregues, do mais vendido ao menos vendido.' },
  { key: 'customers', label: 'Clientes que voltam', icon: 'users', description: 'Clientes atendidos no período e quem já tinha vindo antes.' },
  { key: 'mechanics', label: 'Produção', icon: 'user', description: 'Serviços concluídos por mecânico no período.' },
  {
    key: 'warranty',
    label: 'Retornos em garantia',
    icon: 'shield',
    description: 'Serviços que voltaram na garantia no período, quantas vezes e quem tinha feito o serviço original.',
  },
  {
    key: 'satisfaction',
    label: 'Satisfação',
    icon: 'star',
    description: 'Avaliações dos clientes (0 a 10) respondidas no período, das piores notas para as melhores.',
  },
];

/** Relatórios gerenciais com exportação em CSV (abre no Excel). */
@Component({
  selector: 'app-reports',
  imports: [DecimalPipe, Icon, MoneyPipe, BarChart],
  templateUrl: './reports.html',
  styleUrls: ['../../../services/pages/service-list/service-list.scss', '../../../finance/pages/finance/finance.scss', './reports.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReportsPage implements OnInit {
  private readonly reports = inject(ReportsService);

  /** ?report=satisfaction (link do painel). */
  readonly reportParam = input<string | undefined>(undefined, { alias: 'report' });

  protected readonly tabs = TABS;
  protected readonly report = signal<ReportKey>('revenue');
  protected readonly from = signal(presetRange('month').from);
  protected readonly to = signal(presetRange('month').to);
  protected readonly group = signal<'day' | 'month'>('day');
  protected readonly onlyReturning = signal(false);

  protected readonly result = signal<ReportResult | null>(null);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);

  protected readonly tab = computed(() => TABS.find((tab) => tab.key === this.report())!);
  private readonly query = computed<ReportQuery>(() => ({
    report: this.report(),
    from: this.from(),
    to: this.to(),
    group: this.group(),
    onlyReturning: this.onlyReturning(),
  }));
  protected readonly csvUrl = computed(() => this.reports.csvUrl(this.query()));

  /**
   * Faturamento por período no gráfico (a tabela abaixo traz todos os números).
   * Períodos sem entrega entram com zero: o eixo do tempo fica contínuo.
   */
  protected readonly chart = computed<BarDatum[]>(() => {
    const result = this.result();
    if (result?.report !== 'revenue') return [];

    const isMonth = this.group() === 'month';
    const rows = new Map(result.rows.map((row) => [String(row['period']), row]));
    const periods: string[] = [];
    const [fy, fm, fd] = result.from.split('-').map(Number);
    const end = result.to;
    const cursor = isMonth ? new Date(fy, fm - 1, 1) : new Date(fy, fm - 1, fd);
    while (localDate(cursor) <= end && periods.length < 400) {
      periods.push(localDate(cursor));
      if (isMonth) cursor.setMonth(cursor.getMonth() + 1);
      else cursor.setDate(cursor.getDate() + 1);
    }

    return periods.map((period) => {
      const row = rows.get(period);
      const [year, month, day] = period.split('-');
      const total = Number(row?.['total_cents'] ?? 0);
      const orders = Number(row?.['orders'] ?? 0);
      return {
        label: isMonth ? `${month}/${year.slice(2)}` : `${day}/${month}`,
        title: isMonth ? `${month}/${year}` : `${day}/${month}/${year}`,
        value: total,
        display: formatMoney(total),
        detail: `${orders} ${orders === 1 ? 'OS entregue' : 'OS entregues'}`,
      };
    });
  });

  protected readonly moneyTick = (cents: number): string => {
    const reais = cents / 100;
    return reais >= 1000 ? `R$ ${(reais / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} mil` : `R$ ${Math.round(reais)}`;
  };

  constructor() {
    toObservable(this.query)
      .pipe(
        debounceTime(200),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        tap(() => {
          this.loading.set(true);
          this.failed.set(false);
        }),
        switchMap((query) => (query.from && query.to && query.from <= query.to ? this.reports.get(query).pipe(catchError(() => of(null))) : of(null))),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((result) => {
        this.loading.set(false);
        this.failed.set(result === null);
        if (result) this.result.set(result);
      });
  }

  ngOnInit(): void {
    const key = TABS.find((tab) => tab.key === this.reportParam())?.key;
    if (key) this.report.set(key);
  }

  protected selectReport(key: ReportKey): void {
    if (key === this.report()) return;
    this.result.set(null);
    this.report.set(key);
  }

  protected setPreset(preset: 'month' | 'last-month' | 'last-30' | 'year'): void {
    const range = presetRange(preset);
    this.from.set(range.from);
    this.to.set(range.to);
    if (preset === 'year') this.group.set('month');
  }

  /** Valor de célula formatado conforme o tipo da coluna. */
  protected cell(value: string | number | boolean | null | undefined, type: ColumnType): string {
    if (value === null || value === undefined || value === '') return '—';
    switch (type) {
      case 'money':
        return formatMoney(Number(value));
      case 'percent':
        return `${Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
      case 'int':
        return Number(value).toLocaleString('pt-BR');
      case 'date': {
        const [year, month, day] = String(value).split('-');
        return day ? `${day}/${month}/${year}` : String(value);
      }
      case 'month': {
        const [year, month] = String(value).split('-');
        return month ? `${month}/${year}` : String(value);
      }
      case 'bool':
        return value ? 'Sim' : 'Não';
      case 'phone':
        return formatPhone(String(value));
      default:
        return String(value);
    }
  }

  /** Número da linha de totais (0 quando o relatório não tem). */
  protected total(key: string): number {
    return Number(this.result()?.totals?.[key] ?? 0);
  }

  protected isNumeric(type: ColumnType): boolean {
    return type === 'money' || type === 'int' || type === 'percent';
  }
}
