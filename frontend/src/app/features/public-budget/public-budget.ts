import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { finalize } from 'rxjs';

import { Icon } from '../../shared/components/icon/icon';
import { Logo } from '../../shared/components/logo/logo';
import { PixChargeView } from '../../shared/components/pix-charge/pix-charge';
import { BrFormatPipe } from '../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../shared/pipes/money.pipe';
import { PublicBudget, PublicBudgetService } from './public-budget.service';

/**
 * Página que o cliente abre pelo link do WhatsApp: vê os itens e aprova ou recusa
 * sem precisar ligar para a oficina. Não há login nem dados pessoais na tela.
 */
@Component({
  selector: 'app-public-budget',
  imports: [DatePipe, Icon, Logo, PixChargeView, BrFormatPipe, MoneyPipe],
  templateUrl: './public-budget.html',
  styleUrl: './public-budget.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PublicBudgetPage implements OnInit {
  private readonly budgets = inject(PublicBudgetService);

  /** :token do link. */
  readonly token = input.required<string>();

  protected readonly budget = signal<PublicBudget | null>(null);
  protected readonly loading = signal(true);
  /** Link inválido (404) ou vencido (410). */
  protected readonly loadError = signal<'invalid' | 'expired' | 'failed' | null>(null);

  protected readonly decision = signal<'approve' | 'reject' | null>(null);
  protected readonly name = signal('');
  protected readonly reason = signal('');
  protected readonly sending = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly shopWhatsapp = computed(() => {
    const phone = this.budget()?.shop.phone?.replace(/\D/g, '');
    return phone && phone.length >= 10 ? `https://wa.me/55${phone}` : null;
  });

  ngOnInit(): void {
    this.load();
  }

  protected choose(decision: 'approve' | 'reject'): void {
    this.decision.set(decision);
    this.error.set(null);
    if (decision === 'approve' && !this.name()) this.name.set(this.budget()?.order.customer_first_name ?? '');
  }

  protected confirm(): void {
    const budget = this.budget();
    const decision = this.decision();
    if (!budget || !decision || this.sending()) return;

    this.sending.set(true);
    this.error.set(null);
    const request =
      decision === 'approve'
        ? this.budgets.approve(this.token(), budget.order.total_cents, this.name().trim())
        : this.budgets.reject(this.token(), budget.order.total_cents, this.reason().trim());

    request.pipe(finalize(() => this.sending.set(false))).subscribe({
      next: (updated) => {
        this.budget.set(updated);
        this.decision.set(null);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      },
      error: (error: unknown) => {
        const message =
          error instanceof HttpErrorResponse
            ? ((Object.values(error.error?.errors ?? {})[0] as string[] | undefined)?.[0] ?? error.error?.message)
            : null;
        this.error.set(message ?? 'Não foi possível enviar sua resposta. Tente novamente.');
        // Orçamento mudou ou já foi respondido: recarrega para mostrar o estado atual
        if (error instanceof HttpErrorResponse && error.status === 422) this.load(false);
      },
    });
  }

  private load(showSpinner = true): void {
    if (showSpinner) this.loading.set(true);
    this.budgets
      .get(this.token())
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (budget) => this.budget.set(budget),
        error: (error: unknown) => {
          const status = error instanceof HttpErrorResponse ? error.status : 0;
          this.loadError.set(status === 410 ? 'expired' : status === 404 ? 'invalid' : 'failed');
        },
      });
  }
}
