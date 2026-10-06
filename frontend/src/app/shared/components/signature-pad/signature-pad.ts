import { afterNextRender, ChangeDetectionStrategy, Component, ElementRef, signal, viewChild } from '@angular/core';

/**
 * Quadro para o cliente assinar com o dedo/caneta (ou mouse). Gera PNG com fundo transparente.
 * Usa Pointer Events, então funciona igual em tablet, celular e computador.
 */
@Component({
  selector: 'app-signature-pad',
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    :host {
      display: block;
    }

    .pad {
      position: relative;
      height: 180px;
      border: 1px dashed #d4d4d8;
      border-radius: var(--radius-md);
      background: var(--color-surface);
      touch-action: none;
    }

    canvas {
      display: block;
      width: 100%;
      height: 100%;
      cursor: crosshair;
    }

    .line {
      position: absolute;
      right: 24px;
      bottom: 36px;
      left: 24px;
      border-top: 1px solid var(--color-border);
      pointer-events: none;
    }

    .hint {
      position: absolute;
      bottom: 12px;
      left: 0;
      right: 0;
      color: var(--color-placeholder);
      font-size: 12px;
      text-align: center;
      pointer-events: none;
    }
  `,
  template: `
    <div class="pad">
      <canvas
        #canvas
        (pointerdown)="start($event)"
        (pointermove)="move($event)"
        (pointerup)="end()"
        (pointerleave)="end()"
        (pointercancel)="end()"
        aria-label="Área de assinatura"
        role="img"
      ></canvas>
      <span class="line"></span>
      @if (empty()) {
        <span class="hint">Assine aqui</span>
      }
    </div>
  `,
})
export class SignaturePad {
  readonly empty = signal(true);

  private readonly canvas = viewChild.required<ElementRef<HTMLCanvasElement>>('canvas');
  private drawing = false;
  private last: { x: number; y: number } | null = null;

  constructor() {
    afterNextRender(() => this.resize());
  }

  clear(): void {
    this.resize();
  }

  /** PNG em data URL; null se ninguém assinou. */
  toDataUrl(): string | null {
    return this.empty() ? null : this.canvas().nativeElement.toDataURL('image/png');
  }

  protected start(event: PointerEvent): void {
    event.preventDefault();
    this.canvas().nativeElement.setPointerCapture?.(event.pointerId);
    this.drawing = true;
    this.last = this.point(event);
    this.dot(this.last);
  }

  protected move(event: PointerEvent): void {
    if (!this.drawing || !this.last) return;
    const next = this.point(event);
    const context = this.context();
    context.beginPath();
    context.moveTo(this.last.x, this.last.y);
    context.lineTo(next.x, next.y);
    context.stroke();
    this.last = next;
    this.empty.set(false);
  }

  protected end(): void {
    this.drawing = false;
    this.last = null;
  }

  private dot(point: { x: number; y: number }): void {
    const context = this.context();
    context.beginPath();
    context.arc(point.x, point.y, 1.2, 0, Math.PI * 2);
    context.fill();
    this.empty.set(false);
  }

  private point(event: PointerEvent): { x: number; y: number } {
    const rect = this.canvas().nativeElement.getBoundingClientRect();
    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
  }

  private context(): CanvasRenderingContext2D {
    return this.canvas().nativeElement.getContext('2d')!;
  }

  /** Ajusta a resolução à tela (traço nítido em telas retina) e limpa. */
  private resize(): void {
    const canvas = this.canvas().nativeElement;
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.max(1, Math.round(rect.width * ratio));
    canvas.height = Math.max(1, Math.round(rect.height * ratio));

    const context = this.context();
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.lineWidth = 2.2;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#111111';
    context.fillStyle = '#111111';
    this.empty.set(true);
  }
}
