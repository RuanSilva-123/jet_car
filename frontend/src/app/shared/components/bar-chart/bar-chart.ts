import { afterNextRender, ChangeDetectionStrategy, Component, computed, DestroyRef, ElementRef, inject, input, signal } from '@angular/core';

export interface BarDatum {
  label: string;
  /** Rótulo completo do tooltip (ex.: "05/10/2026"). */
  title: string;
  value: number;
  /** Valor formatado para o tooltip (ex.: "R$ 1.234,56"). */
  display: string;
  /** Linha extra do tooltip (ex.: "3 OS entregues"). */
  detail?: string;
}

const HEIGHT = 220;
const PAD = { top: 12, right: 8, bottom: 26, left: 64 };

/** Arredonda o topo da escala para um número "limpo" (1, 2, 2,5 ou 5 × 10ⁿ). */
function niceMax(value: number): number {
  if (value <= 0) return 1;
  const power = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 2.5, 5, 10].find((factor) => factor * power >= value) ?? 10;
  return step * power;
}

/**
 * Colunas de uma série (ex.: faturamento por dia). Barras finas com topo arredondado,
 * grade discreta e tooltip no hover/foco. A tabela do relatório é a visão acessível completa.
 */
@Component({
  selector: 'app-bar-chart',
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

    .tick {
      fill: var(--color-text-muted);
      font-size: 11px;
      font-variant-numeric: tabular-nums;
    }

    .bar {
      fill: var(--color-brand);
      transition: opacity 0.12s;
    }

    .has-active .bar:not(.is-active) {
      opacity: 0.45;
    }

    .hit {
      fill: transparent;
      cursor: default;
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
      transform: translate(-50%, calc(-100% - 8px));

      small {
        color: var(--color-text-muted);
      }

      strong {
        font-size: 14px;
        font-variant-numeric: tabular-nums;
      }
    }
  `,
  template: `
    <svg [attr.viewBox]="'0 0 ' + width() + ' ' + height" role="img" [attr.aria-label]="ariaLabel()" [class.has-active]="active() !== null">
      @for (tick of ticks(); track tick.y) {
        <line class="grid" [attr.x1]="pad.left" [attr.x2]="width() - pad.right" [attr.y1]="tick.y" [attr.y2]="tick.y" />
        <text class="tick" [attr.x]="pad.left - 8" [attr.y]="tick.y + 4" text-anchor="end">{{ tick.label }}</text>
      }
      @for (bar of bars(); track $index) {
        <path class="bar" [class.is-active]="active() === $index" [attr.d]="bar.path" />
        @if (bar.showLabel) {
          <text class="tick" [attr.x]="bar.cx" [attr.y]="height - 8" text-anchor="middle">{{ bar.label }}</text>
        }
        <rect
          class="hit"
          [attr.x]="bar.slotX"
          [attr.y]="pad.top"
          [attr.width]="bar.slotWidth"
          [attr.height]="height - pad.top - pad.bottom"
          tabindex="0"
          [attr.aria-label]="bar.title + ': ' + bar.display"
          (mouseenter)="active.set($index)"
          (mouseleave)="active.set(null)"
          (focus)="active.set($index)"
          (blur)="active.set(null)"
        />
      }
    </svg>
    @if (tooltip(); as tip) {
      <div class="tooltip" [style.left.%]="tip.left" [style.top.%]="tip.top">
        <small>{{ tip.title }}</small>
        <strong>{{ tip.display }}</strong>
        @if (tip.detail) {
          <small>{{ tip.detail }}</small>
        }
      </div>
    }
  `,
})
export class BarChart {
  readonly data = input.required<BarDatum[]>();
  /** Formata os valores do eixo (ex.: centavos → "R$ 1 mil"). */
  readonly formatTick = input<(value: number) => string>((value) => String(value));
  readonly ariaLabel = input('Gráfico de colunas');

  /** Largura real do gráfico (viewBox 1:1 com a tela: o texto não estica). */
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

  private readonly max = computed(() => niceMax(Math.max(0, ...this.data().map((item) => item.value))));

  protected readonly ticks = computed(() => {
    const plot = HEIGHT - PAD.top - PAD.bottom;
    return [0, 0.25, 0.5, 0.75, 1].map((ratio) => ({
      y: PAD.top + plot * (1 - ratio),
      label: this.formatTick()(this.max() * ratio),
    }));
  });

  protected readonly bars = computed(() => {
    const data = this.data();
    const plotWidth = this.width() - PAD.left - PAD.right;
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const slot = plotWidth / Math.max(data.length, 1);
    const barWidth = Math.max(2, Math.min(24, slot - 2));
    // Rótulos do eixo X espaçados para não colidirem (~1 a cada 56px)
    const every = Math.max(1, Math.ceil(56 / slot));
    const baseline = PAD.top + plotHeight;

    return data.map((item, index) => {
      const h = item.value > 0 ? Math.max(2, (item.value / this.max()) * plotHeight) : 0;
      const x = PAD.left + slot * index + (slot - barWidth) / 2;
      const r = Math.min(4, barWidth / 2, h);
      const top = baseline - h;
      // Topo arredondado (4px), base reta
      const path = h
        ? `M${x},${baseline} V${top + r} Q${x},${top} ${x + r},${top} H${x + barWidth - r} Q${x + barWidth},${top} ${x + barWidth},${top + r} V${baseline} Z`
        : '';
      return {
        ...item,
        path,
        cx: x + barWidth / 2,
        top,
        slotX: PAD.left + slot * index,
        slotWidth: slot,
        showLabel: index % every === 0,
      };
    });
  });

  protected readonly tooltip = computed(() => {
    const index = this.active();
    const bar = index === null ? null : this.bars()[index];
    if (!bar) return null;
    return {
      ...bar,
      left: (bar.cx / this.width()) * 100,
      top: (Math.min(bar.top, HEIGHT - PAD.bottom - 4) / HEIGHT) * 100,
    };
  });
}
