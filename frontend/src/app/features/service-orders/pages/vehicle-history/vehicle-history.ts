import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { StatusBadge } from '../../components/status-badge/status-badge';
import { ServiceOrdersService, VehicleHistory as VehicleHistoryData } from '../../services/service-orders.service';

/** Tudo o que já foi feito no veículo: uma OS por cartão, da mais recente à mais antiga. */
@Component({
  selector: 'app-vehicle-history',
  imports: [RouterLink, DatePipe, Icon, BrFormatPipe, MoneyPipe, StatusBadge],
  templateUrl: './vehicle-history.html',
  styleUrl: './vehicle-history.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VehicleHistory implements OnInit {
  private readonly orders = inject(ServiceOrdersService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  /** :id do veículo. */
  readonly id = input.required<string>();

  protected readonly history = signal<VehicleHistoryData | null>(null);
  protected readonly loading = signal(true);

  /** Total de serviços concluídos em todas as OS (exceto canceladas). */
  protected readonly servicesDone = computed(
    () =>
      this.history()
        ?.orders.filter((order) => order.status !== 'canceled')
        .reduce((total, order) => total + (order.items?.filter((item) => item.is_done).length ?? 0), 0) ?? 0,
  );

  protected readonly typeIcon = computed(() => {
    const type = this.history()?.vehicle.type;
    return type === 'motorcycle' ? 'bike' : type === 'truck' ? 'truck' : 'car';
  });

  ngOnInit(): void {
    this.orders
      .vehicleHistory(Number(this.id()))
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (history) => this.history.set(history),
        error: () => {
          this.toast.error('Veículo não encontrado.');
          this.router.navigate(['/service-orders']);
        },
      });
  }
}
