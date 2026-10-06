import { afterNextRender, ChangeDetectionStrategy, Component, computed, DestroyRef, ElementRef, inject, input, signal } from '@angular/core';

export interface LinePoint {
  label: string;
  /** Rótulo completo do tooltip (ex.: "05/10/2026"). */
  title: string;
  value: number;
  display: string;
  detail?: string;
  /** Ponto previsto (futuro): desenhado tracejado. */
  projected?: boolean;
}

const HEIGHT = 220;
const PAD = { top: 12, right: 12, bottom: 26, left: 72 };

/** Escala "limpa" (1, 2, 2,5 ou 5 × 10ⁿ). */
function nice(value: number): number {
  if (value <= 0) return 0;
  const power = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 2.5, 5, 10].find((factor) => factor * power >= value) ?? 10;
  return step * power;
}

/**
 * Linha de uma série com sinal (ex.: saldo do caixa por dia). Trecho realizado contínuo,
 * trecho previsto tracejado, linha do zero destacada e tooltip no hover/foco.
 */
@Component({
  selector: 'app-line-chart',
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    :host {
      position: relative;
      display: block;
    }

    svg {
      display: block;
      width: 100%;
      height: auto;
      overflow: visible;
    }

    .grid {
      stroke: var(--color-border);
      stroke-width: 1;
    }

    .zero {
      stroke: var(--color-text-muted);
      stroke-width: 1;
      stroke-dasharray: 2 3;
    }

    .tick {
      fill: var(--color-text-muted);
      font-size: 11px;
      font-variant-numeric: tabular-nums;
    }

    .area {
      fill: var(--color-brand);
      opacity: 0.08;
    }

    .line {
      fill: none;
      stroke: var(--color-brand);
      stroke-width: 2.5;
      stroke-linejoin: round;
      stroke-linecap: round;

      &--projected {
        stroke-dasharray: 5 5;
        opacity: 0.7;
      }
    }

    .dot {
      fill: var(--color-surface);
      stroke: var(--color-brand);
      stroke-width: 2.5;

      &.is-negative {
        stroke: var(--color-danger);
      }
    }

    .cursor {
      stroke: var(--color-border);
      stroke-width: 1;
    }

    .hit {
      fill: transparent;
      outline: none;
    }

    .tooltip {
      position: absolute;
      z-index: 2;
      display: grid;
      gap: 1px;
      padding: 8px 10px;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      background: var(--color-surface);
      box-shadow: var(--shadow-md);
      color: var(--color-text);
      font-size: 12px;
      white-space: nowrap;
      pointer-events: none;
      transform: translate(-50%, calc(-100% - 10px));

      small {
        color: var(--color-text-muted);
      }

      strong {
        font-size: 14px;
        font-variant-numeric: tabular-nums;

        &.is-negative {
          color: var(--color-danger);
        }
      }
    }
  `,
  template: `
    <svg [attr.viewBox]="'0 0 ' + width() + ' ' + height" role="img" [attr.aria-label]="ariaLabel()">
      @for (tick of ticks(); track tick.y) {
        <line class="grid" [attr.x1]="pad.left" [attr.x2]="width() - pad.right" [attr.y1]="tick.y" [attr.y2]="tick.y" />
        <text class="tick" [attr.x]="pad.left - 8" [attr.y]="tick.y + 4" text-anchor="end">{{ tick.label }}</text>
      }
      @if (geometry(); as geo) {
        <line class="zero" [attr.x1]="pad.left" [attr.x2]="width() - pad.right" [attr.y1]="geo.zeroY" [attr.y2]="geo.zeroY" />
        <path class="area" [attr.d]="geo.area" />
        @if (geo.solid) {
          <path class="line" [attr.d]="geo.solid" />
        }
        @if (geo.dashed) {
          <path class="line line--projected" [attr.d]="geo.dashed" />
        }
        @for (point of geo.points; track $index) {
          @if (point.showLabel) {
            <text class="tick" [attr.x]="point.x" [attr.y]="height - 8" text-anchor="middle">{{ point.label }}</text>
          }
        }
        @if (activePoint(); as point) {
          <line class="cursor" [attr.x1]="point.x" [attr.x2]="point.x" [attr.y1]="pad.top" [attr.y2]="height - pad.bottom" />
          <circle class="dot" [class.is-negative]="point.value < 0" [attr.cx]="point.x" [attr.cy]="point.y" r="4.5" />
        }
        @for (point of geo.points; track $index) {
          <rect
            class="hit"
            [attr.x]="point.slotX"
            [attr.y]="pad.top"
            [attr.width]="point.slotWidth"
            [attr.height]="height - pad.top - pad.bottom"
            tabindex="0"
            [attr.aria-label]="point.title + ': ' + point.display"
            (mouseenter)="active.set($index)"
            (mouseleave)="active.set(null)"
            (focus)="active.set($index)"
            (blur)="active.set(null)"
          />
        }
      }
    </svg>
    @if (activePoint(); as tip) {
      <div class="tooltip" [style.left.%]="(tip.x / width()) * 100" [style.top.%]="(tip.y / height) * 100">
        <small>{{ tip.title }}{{ tip.projected ? ' · previsto' : '' }}</small>
        <strong [class.is-negative]="tip.value < 0">{{ tip.display }}</strong>
        @if (tip.detail) {
          <small>{{ tip.detail }}</small>
        }
      </div>
    }
  `,
})
export class LineChart {
  readonly data = input.required<LinePoint[]>();
  readonly formatTick = input<(value: number) => string>((value) => String(value));
  readonly ariaLabel = input('Gráfico de linha');

  protected readonly width = signal(720);
  protected readonly height = HEIGHT;
  protected readonly pad = PAD;
  protected readonly active = signal<number | null>(null);

  constructor() {
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    let observer: ResizeObserver | undefined;
    afterNextRender(() => {
      observer = new ResizeObserver(([entry]) => this.width.set(Math.max(280, Math.round(entry.contentRect.width))));
      observer.observe(host);
    });
    inject(DestroyRef).onDestroy(() => observer?.disconnect());
  }

  /** Faixa do eixo Y sempre incluindo o zero, em passos "limpos" (o zero cai numa linha da grade). */
  private readonly range = computed(() => {
    const values = this.data().map((point) => point.value);
    const low = Math.min(0, ...values);
    const high = Math.max(0, ...values);
    const step = nice((high - low) / 4) || 1;
    return { min: Math.floor(low / step) * step, max: Math.max(Math.ceil(high / step) * step, Math.floor(low / step) * step + step), step };
  });

  private y(value: number): number {
    const { min, max } = this.range();
    const plot = HEIGHT - PAD.top - PAD.bottom;
    return PAD.top + plot * (1 - (value - min) / (max - min));
  }

  protected readonly ticks = computed(() => {
    const { min, max, step } = this.range();
    const ticks = [];
    for (let value = min; value <= max + step / 2; value += step) {
      ticks.push({ y: this.y(value), label: this.formatTick()(value) });
    }
    return ticks;
  });

  protected readonly geometry = computed(() => {
    const data = this.data();
    if (!data.length) return null;

    const plotWidth = this.width() - PAD.left - PAD.right;
    const slot = plotWidth / data.length;
    const every = Math.max(1, Math.ceil(56 / slot));
    const points = data.map((point, index) => ({
      ...point,
      x: PAD.left + slot * index + slot / 2,
      y: this.y(point.value),
      slotX: PAD.left + slot * index,
      slotWidth: slot,
      showLabel: index % every === 0,
    }));

    const path = (list: typeof points) => list.map((point, index) => `${index ? 'L' : 'M'}${point.x},${point.y}`).join(' ');
    const firstProjected = points.findIndex((point) => point.projected);
    const realized = firstProjected === -1 ? points : points.slice(0, firstProjected);
    // O tracejado parte do último ponto realizado: a linha não fica com buraco
    const projected = firstProjected === -1 ? [] : points.slice(Math.max(0, firstProjected - 1));
    const zeroY = this.y(0);

    return {
      points,
      zeroY,
      solid: realized.length > 1 ? path(realized) : '',
      dashed: projected.length > 1 ? path(projected) : '',
      area: `${path(points)} L${points[points.length - 1].x},${zeroY} L${points[0].x},${zeroY} Z`,
    };
  });

  protected readonly activePoint = computed(() => {
    const index = this.active();
    return index === null ? null : (this.geometry()?.points[index] ?? null);
  });
}
