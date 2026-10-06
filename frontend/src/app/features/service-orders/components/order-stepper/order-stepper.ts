import { ChangeDetectionStrategy, Component, input } from '@angular/core';

import { Icon } from '../../../../shared/components/icon/icon';
import { FLOW_STEPS } from '../../models/service-order';

/** Etapas da OS (entrada → diagnóstico → orçamento → aprovação → execução → entrega). */
@Component({
  selector: 'app-order-stepper',
  imports: [Icon],
  template: `
    <ol class="stepper" aria-label="Etapas da OS">
      @for (step of steps; track step; let index = $index) {
        <li
          class="stepper__step"
          [class.is-done]="index < current()"
          [class.is-current]="index === current()"
          [attr.aria-current]="index === current() ? 'step' : null"
        >
          <span class="stepper__dot">
            @if (index < current()) {
              <app-icon name="check" [size]="12" />
            } @else {
              {{ index + 1 }}
            }
          </span>
          <span class="stepper__label">{{ step }}</span>
        </li>
      }
    </ol>
  `,
  styleUrl: './order-stepper.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrderStepper {
  /** Índice da etapa atual (FLOW_STEPS.length = todas concluídas). */
  readonly current = input.required<number>();

  protected readonly steps = FLOW_STEPS;
}
