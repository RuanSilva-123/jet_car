import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { Icon } from '../../../../shared/components/icon/icon';
import { STATUS_META, ServiceOrderStatus } from '../../models/service-order';

/** Selo colorido do status da OS. */
@Component({
  selector: 'app-status-badge',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    :host {
      display: inline-flex;
    }

    .status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
      white-space: nowrap;
    }

    .status--lg {
      padding: 6px 12px;
      font-size: 13px;
    }

    .tone-info {
      background: #eff6ff;
      color: #1d4ed8;
    }

    .tone-brand {
      background: var(--color-brand-soft);
      color: var(--color-brand);
    }

    .tone-warning {
      background: var(--color-warning-soft);
      color: var(--color-warning);
    }

    .tone-success {
      background: var(--color-success-soft);
      color: var(--color-success);
    }

    .tone-ink {
      background: var(--color-ink);
      color: #fff;
    }

    .tone-neutral {
      background: var(--color-neutral-soft);
      color: var(--color-text-muted);
    }
  `,
  template: `
    <span class="status tone-{{ meta().tone }}" [class.status--lg]="size() === 'lg'">
      <app-icon [name]="meta().icon" [size]="size() === 'lg' ? 14 : 12" />
      {{ meta().label }}
    </span>
  `,
})
export class StatusBadge {
  readonly status = input.required<ServiceOrderStatus>();
  readonly size = input<'sm' | 'lg'>('sm');

  protected readonly meta = computed(() => STATUS_META[this.status()]);
}
