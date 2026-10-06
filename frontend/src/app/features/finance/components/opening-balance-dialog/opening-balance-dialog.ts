import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { finalize } from 'rxjs';

import { Icon } from '../../../../shared/components/icon/icon';
import { centsToInput, maskMoney, moneyToCents } from '../../../../shared/utils/br-format';
import { localDate } from '../../dates';
import { CashFlowSettings, PayablesService } from '../../payables.service';

/** Saldo que havia em caixa + banco numa data: ponto de partida do fluxo de caixa (só o master). */
@Component({
  selector: 'app-opening-balance-dialog',
  imports: [Icon],
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dialog #dialog (cancel)="onCancel($event)" aria-labelledby="opening-title">
      <form (submit)="$event.preventDefault(); submit()" novalidate>
        <header class="dialog__header">
          <span class="dialog__icon"><app-icon name="wallet" [size]="20" /></span>
          <div>
            <h2 id="opening-title">Saldo inicial do caixa</h2>
            <p>Quanto havia no caixa e nas contas da oficina numa data. Daí em diante o sistema soma o que entra e desconta o que é pago.</p>
          </div>
          <button type="button" class="btn btn--icon" (click)="closed.emit()" aria-label="Fechar" [disabled]="saving()">
            <app-icon name="close" [size]="18" />
          </button>
        </header>

        <div class="dialog__body">
          <div class="dialog__grid">
            <div class="field">
              <label for="opening-date">Data do saldo</label>
              <div class="field__control">
                <input id="opening-date" type="date" [max]="today" [value]="date()" (input)="date.set($any($event.target).value)" />
              </div>
            </div>
            <div class="field">
              <label for="opening-amount">Saldo nessa data</label>
              <div class="field__control">
                <span class="field__suffix">R$</span>
                <input id="opening-amount" inputmode="numeric" [value]="amount()" (input)="onAmount($event)" />
              </div>
            </div>
          </div>
          <label class="checkbox">
            <input type="checkbox" [checked]="negative()" (change)="negative.set($any($event.target).checked)" />
            <span>Saldo negativo (cheque especial)</span>
          </label>
          <small class="muted">Dica: use o saldo do extrato do banco somado ao dinheiro do caixa no fim de um dia.</small>

          @if (error(); as message) {
            <div class="dialog__alert" role="alert"><app-icon name="alert" [size]="16" /> {{ message }}</div>
          }
        </div>

        <footer class="dialog__footer">
          <button type="button" class="btn btn--ghost" (click)="closed.emit()" [disabled]="saving()">Cancelar</button>
          <button type="submit" class="btn btn--primary" [disabled]="saving()">
            @if (saving()) {
              <span class="spinner"></span>
            } @else {
              <app-icon name="check" [size]="16" />
            }
            Salvar saldo
          </button>
        </footer>
      </form>
    </dialog>
  `,
})
export class OpeningBalanceDialog {
  private readonly payables = inject(PayablesService);

  readonly open = input(false);
  readonly settings = input<CashFlowSettings | null>(null);

  readonly saved = output<CashFlowSettings>();
  readonly closed = output<void>();

  protected readonly today = localDate(new Date());
  protected readonly date = signal(this.today);
  protected readonly amount = signal('');
  protected readonly negative = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        const current = this.settings();
        this.date.set(current?.opening_date ?? this.today);
        this.amount.set(centsToInput(Math.abs(current?.opening_balance_cents ?? 0)));
        this.negative.set((current?.opening_balance_cents ?? 0) < 0);
        this.error.set(null);
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected onAmount(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = maskMoney(element.value);
    this.amount.set(element.value);
  }

  protected submit(): void {
    if (this.saving()) return;
    if (!this.date()) {
      this.error.set('Informe a data do saldo.');
      return;
    }

    const cents = moneyToCents(this.amount()) * (this.negative() ? -1 : 1);
    this.error.set(null);
    this.saving.set(true);
    this.payables
      .saveOpeningBalance({ opening_balance_cents: cents, opening_date: this.date() })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (settings) => this.saved.emit(settings),
        error: (error: unknown) => {
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? (error instanceof HttpErrorResponse ? error.error?.message : null) ?? 'Não foi possível salvar.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }
}
