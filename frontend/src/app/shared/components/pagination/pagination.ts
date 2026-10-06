import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

import { Paginated } from '../../../core/http/api';
import { Icon } from '../icon/icon';

/** Rodapé de paginação para coleções paginadas da API. */
@Component({
  selector: 'app-pagination',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    :host {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 20px;
      border-top: 1px solid var(--color-border);
      color: var(--color-text-muted);
      font-size: 13px;
    }

    strong {
      color: var(--color-text);
    }

    .buttons {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .page {
      min-width: 48px;
      text-align: center;
      font-variant-numeric: tabular-nums;
    }
  `,
  template: `
    <span>
      Mostrando <strong>{{ meta().from }}–{{ meta().to }}</strong> de <strong>{{ meta().total }}</strong>
    </span>
    <div class="buttons">
      <button
        type="button"
        class="btn btn--ghost btn--sm"
        [disabled]="meta().current_page <= 1"
        (click)="pageChange.emit(meta().current_page - 1)"
      >
        <app-icon name="chevron-left" [size]="16" /> Anterior
      </button>
      <span class="page">{{ meta().current_page }} / {{ meta().last_page }}</span>
      <button
        type="button"
        class="btn btn--ghost btn--sm"
        [disabled]="meta().current_page >= meta().last_page"
        (click)="pageChange.emit(meta().current_page + 1)"
      >
        Próxima <app-icon name="chevron-right" [size]="16" />
      </button>
    </div>
  `,
})
export class Pagination {
  readonly meta = input.required<Paginated<unknown>['meta']>();
  readonly pageChange = output<number>();
}
