import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, input, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { debounceTime, distinctUntilChanged, finalize, Observable, Subject, switchMap } from 'rxjs';

import { ToastService } from '../../../../core/services/toast.service';
import { Combobox, ComboboxOption } from '../../../../shared/components/combobox/combobox';
import { Icon, IconName } from '../../../../shared/components/icon/icon';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { formatMoney, formatPlate, formatQuantity, maskQuantity, parseQuantity } from '../../../../shared/utils/br-format';
import { ServiceFormDialog } from '../../../services/components/service-form-dialog/service-form-dialog';
import { LaborService } from '../../../services/models/labor-service';
import { LaborServicesService } from '../../../services/services/labor-services.service';
import { BudgetDecision, BudgetDecisionDialog } from '../../components/budget-decision-dialog/budget-decision-dialog';
import { OrderStepper } from '../../components/order-stepper/order-stepper';
import { StatusBadge } from '../../components/status-badge/status-badge';
import { StatusChange, StatusDialog } from '../../components/status-dialog/status-dialog';
import {
  flowStep,
  lineCount,
  nextStep,
  ServiceOrder,
  ServiceOrderEvent,
  ServiceOrderItem,
  ServiceOrderPart,
  ServiceOrderStatus,
} from '../../models/service-order';
import { ServiceOrdersService } from '../../services/service-orders.service';

const EVENT_ICONS: Record<ServiceOrderEvent['type'], IconName> = {
  created: 'clipboard',
  status_changed: 'clock',
  item_added: 'plus',
  item_removed: 'trash',
  item_done: 'check',
  item_undone: 'clock',
  part_added: 'package',
  part_removed: 'trash',
  updated: 'pencil',
  note: 'message',
  budget_updated: 'wallet',
  budget_sent: 'arrow-right',
  budget_approved: 'check',
  budget_rejected: 'close',
};

@Component({
  selector: 'app-order-detail',
  imports: [RouterLink, DatePipe, Icon, Combobox, BrFormatPipe, MoneyPipe, StatusBadge, StatusDialog, BudgetDecisionDialog, ServiceFormDialog, OrderStepper],
  templateUrl: './order-detail.html',
  styleUrl: './order-detail.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrderDetail implements OnInit {
  private readonly orders = inject(ServiceOrdersService);
  private readonly laborServices = inject(LaborServicesService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  readonly id = input.required<string>();

  protected readonly order = signal<ServiceOrder | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  /** Item com checkbox sendo salvo (evita duplo clique). */
  protected readonly savingItem = signal<number | null>(null);
  /** Linha sendo removida ("item-3" / "part-7"). */
  protected readonly removing = signal<string | null>(null);

  protected readonly statusDialogOpen = signal(false);
  protected readonly decisionOpen = signal(false);
  protected readonly decisionMode = signal<'approve' | 'reject'>('approve');

  protected readonly note = signal('');
  protected readonly savingNote = signal(false);

  // Diagnóstico: serviços do catálogo e peças (sem valores)
  protected readonly serviceOptions = signal<ComboboxOption[]>([]);
  protected readonly searchingServices = signal(false);
  protected readonly addingItem = signal(false);
  protected readonly newServiceOpen = signal(false);
  protected readonly partName = signal('');
  protected readonly partNumber = signal('');
  protected readonly partQuantity = signal('1');
  protected readonly addingPart = signal(false);
  protected readonly partError = signal<string | null>(null);

  protected readonly eventIcons = EVENT_ICONS;

  protected readonly nextStep = computed(() => {
    const order = this.order();
    return order ? nextStep(order) : null;
  });

  /** Etapa atual do fluxo (null = cancelada). */
  protected readonly flow = computed(() => {
    const order = this.order();
    return order ? flowStep(order) : null;
  });

  protected readonly approved = computed(() => !!this.order()?.budget_approved_at);
  protected readonly canEdit = computed(() => !!this.order() && !this.order()!.is_final);
  protected readonly lines = computed(() => (this.order() ? lineCount(this.order()!) : 0));
  protected readonly unpriced = computed(() => this.order()?.unpriced_count ?? 0);
  /** Todos os serviços/peças com valor: pode gerar PDF final, enviar e aprovar. */
  protected readonly budgetReady = computed(() => this.lines() > 0 && this.unpriced() === 0);

  protected readonly doneCount = computed(() => this.order()?.items?.filter((item) => item.is_done).length ?? 0);
  protected readonly totalCount = computed(() => this.order()?.items?.length ?? 0);
  protected readonly progress = computed(() =>
    this.totalCount() ? Math.round((this.doneCount() / this.totalCount()) * 100) : 0,
  );

  /** Orçamento pode receber resposta do cliente (aprovar/recusar). */
  protected readonly awaitingDecision = computed(() => {
    const order = this.order();
    if (!order || order.is_final || !this.budgetReady()) return false;
    return order.status === 'open' || order.status === 'waiting_approval' || order.budget_changed_after_approval;
  });

  /** Tudo feito e a OS ainda em execução: sugere concluir. */
  protected readonly suggestComplete = computed(() => {
    const order = this.order();
    return (
      !!order &&
      this.approved() &&
      this.totalCount() > 0 &&
      this.doneCount() === this.totalCount() &&
      (order.status === 'in_progress' || order.status === 'waiting_parts')
    );
  });

  protected readonly isLate = computed(() => {
    const order = this.order();
    const today = new Date().toISOString().slice(0, 10);
    return !!order?.expected_at && !order.is_final && order.status !== 'completed' && order.expected_at < today;
  });

  protected readonly budgetPdf = computed(() => this.pdf('budget'));
  protected readonly reportPdf = computed(() => this.pdf('report'));

  /** Comprovante faz mais sentido com o serviço concluído/entregue. */
  protected readonly reportReady = computed(() => ['completed', 'delivered'].includes(this.order()?.status ?? ''));

  /** Link do WhatsApp com o resumo do orçamento (o PDF é anexado pela própria pessoa). */
  protected readonly whatsappBudgetLink = computed(() => {
    const order = this.order();
    if (!order?.customer || !order.vehicle) return null;

    const lines = [
      `Olá, ${order.customer.name.split(' ')[0]}! Segue o orçamento da OS #${order.number}`,
      `para o ${order.vehicle.brand} ${order.vehicle.model}${order.vehicle.plate ? ` (${formatPlate(order.vehicle.plate)})` : ''}:`,
      '',
      ...(order.items ?? []).map((item) => `• ${item.name}: ${formatMoney(item.price_cents ?? 0)}`),
      ...(order.parts ?? []).map((part) => `• ${part.name} (${formatQuantity(part.quantity)}x): ${formatMoney(part.total_cents ?? 0)}`),
      '',
      ...(order.discount_cents > 0 ? [`Desconto: −${formatMoney(order.discount_cents)}`] : []),
      `*Total: ${formatMoney(order.total_cents)}*`,
      '',
      'Envio o PDF com os detalhes. Podemos seguir com o serviço?',
    ];

    return `https://wa.me/55${order.customer.phone}?text=${encodeURIComponent(lines.join('\n'))}`;
  });

  private readonly serviceSearch$ = new Subject<string>();

  ngOnInit(): void {
    this.serviceSearch$
      .pipe(
        debounceTime(200),
        distinctUntilChanged(),
        switchMap((search) => {
          this.searchingServices.set(true);
          return this.laborServices
            .list({ search, category: 'all', status: 'active', page: 1, perPage: 50 })
            .pipe(finalize(() => this.searchingServices.set(false)));
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((result) => this.serviceOptions.set(result.data.map((service) => ({ code: String(service.id), name: service.name }))));

    this.orders
      .get(Number(this.id()))
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (order) => this.order.set(order),
        error: () => {
          this.toast.error('OS não encontrada.');
          this.router.navigate(['/service-orders']);
        },
      });
  }

  protected runNextStep(): void {
    const step = this.nextStep();
    const order = this.order();
    if (!step || !order) return;

    switch (step.kind) {
      case 'status':
        this.applyStatus({ status: step.status, note: '' });
        return;
      case 'diagnose':
        this.focusDiagnosis();
        return;
      case 'edit-budget':
        this.router.navigate(['/service-orders', order.id, 'budget']);
        return;
      case 'send-budget':
        this.sendBudget();
        return;
      case 'approve-budget':
        this.openDecision('approve');
    }
  }

  // --- diagnóstico: o que precisa ser feito ---------------------------------------

  protected onServiceQuery(search: string): void {
    this.serviceSearch$.next(search.trim());
  }

  protected addService(option: ComboboxOption): void {
    const order = this.order();
    if (!order || this.addingItem()) return;

    this.addingItem.set(true);
    this.orders
      .addItem(order.id, Number(option.code))
      .pipe(finalize(() => this.addingItem.set(false)))
      .subscribe({
        next: (updated) => this.order.set(updated),
        error: (error: unknown) => this.toast.error(this.describeError(error)),
      });
  }

  protected onServiceCreated(service: LaborService): void {
    this.addService({ code: String(service.id), name: service.name });
  }

  protected removeItem(item: ServiceOrderItem): void {
    const order = this.order();
    if (!order) return;
    this.removeLine(`item-${item.id}`, this.orders.removeItem(order.id, item.id));
  }

  protected onPartQuantity(event: Event): void {
    const input = event.target as HTMLInputElement;
    input.value = maskQuantity(input.value);
    this.partQuantity.set(input.value);
  }

  protected addPart(): void {
    const order = this.order();
    const name = this.partName().trim();
    const quantity = parseQuantity(this.partQuantity());
    if (!order || this.addingPart()) return;

    if (!name) {
      this.partError.set('Informe o nome da peça.');
      return;
    }
    if (quantity <= 0) {
      this.partError.set('Quantidade deve ser maior que zero.');
      return;
    }

    this.partError.set(null);
    this.addingPart.set(true);
    this.orders
      .addPart(order.id, { name, part_number: this.partNumber().trim(), quantity })
      .pipe(finalize(() => this.addingPart.set(false)))
      .subscribe({
        next: (updated) => {
          this.order.set(updated);
          this.partName.set('');
          this.partNumber.set('');
          this.partQuantity.set('1');
          document.getElementById('part-name')?.focus();
        },
        error: (error: unknown) => this.partError.set(this.describeError(error)),
      });
  }

  protected removePart(part: ServiceOrderPart): void {
    const order = this.order();
    if (!order) return;
    this.removeLine(`part-${part.id}`, this.orders.removePart(order.id, part.id));
  }

  /** Leva até o campo de adicionar serviço. */
  protected focusDiagnosis(): void {
    const input = document.getElementById('diagnosis-service');
    input?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    input?.focus({ preventScroll: true });
  }

  private removeLine(key: string, request: Observable<ServiceOrder>): void {
    if (this.removing()) return;
    this.removing.set(key);
    request.pipe(finalize(() => this.removing.set(null))).subscribe({
      next: (updated) => this.order.set(updated),
      error: (error: unknown) => this.toast.error(this.describeError(error)),
    });
  }

  // --- status e orçamento ------------------------------------------------------------

  protected completeOrder(): void {
    this.applyStatus({ status: 'completed', note: '' });
  }

  protected openStatusDialog(): void {
    this.statusDialogOpen.set(true);
  }

  protected openDecision(mode: 'approve' | 'reject'): void {
    this.decisionMode.set(mode);
    this.decisionOpen.set(true);
  }

  protected applyStatus(change: StatusChange): void {
    const order = this.order();
    if (!order) return;

    this.run(this.orders.changeStatus(order.id, change.status, change.note), () => {
      this.statusDialogOpen.set(false);
      this.toast.success(`OS ${this.order()?.status_label.toLowerCase()}.`);
    });
  }

  /** Marca o orçamento como enviado (status "Aguardando aprovação"). */
  protected sendBudget(silent = false): void {
    const order = this.order();
    if (!order) return;

    this.run(this.orders.sendBudget(order.id), () => {
      if (!silent) this.toast.success('Orçamento marcado como enviado. Agora é aguardar a resposta do cliente.');
    });
  }

  /** WhatsApp abre numa nova aba; se o orçamento ainda não tinha sido enviado, já registra o envio. */
  protected onWhatsappSend(): void {
    const order = this.order();
    if (order && this.budgetReady() && (order.status === 'open' || order.budget_changed_after_approval)) {
      this.sendBudget(true);
    }
  }

  protected decide(decision: BudgetDecision): void {
    const order = this.order();
    if (!order) return;

    const request = decision.approved
      ? this.orders.approveBudget(order.id, decision.note)
      : this.orders.rejectBudget(order.id, decision.note, decision.cancel);

    this.run(request, () => {
      this.decisionOpen.set(false);
      this.toast.success(
        decision.approved
          ? 'Orçamento aprovado. Serviço em andamento.'
          : decision.cancel
            ? 'Orçamento recusado. OS cancelada.'
            : 'Orçamento recusado. Revise os valores e envie de novo.',
      );
    });
  }

  protected toggleItem(item: ServiceOrderItem): void {
    const order = this.order();
    if (!order || order.is_final || !this.approved() || this.savingItem()) return;

    this.savingItem.set(item.id);
    this.orders
      .setItemDone(order.id, item.id, !item.is_done)
      .pipe(finalize(() => this.savingItem.set(null)))
      .subscribe({
        next: (updated) => this.order.set(updated),
        error: (error: unknown) => this.toast.error(this.describeError(error)),
      });
  }

  protected addNote(): void {
    const order = this.order();
    const text = this.note().trim();
    if (!order || !text) return;

    this.savingNote.set(true);
    this.orders
      .addNote(order.id, text)
      .pipe(finalize(() => this.savingNote.set(false)))
      .subscribe({
        next: (updated) => {
          this.order.set(updated);
          this.note.set('');
        },
        error: (error: unknown) => this.toast.error(this.describeError(error)),
      });
  }

  protected whatsappLink(phone: string): string {
    return `https://wa.me/55${phone}`;
  }

  private pdf(document: 'budget' | 'report'): { view: string; download: string } | null {
    const order = this.order();
    return order
      ? { view: this.orders.pdfUrl(order.id, document), download: this.orders.pdfUrl(order.id, document, true) }
      : null;
  }

  private run(request: Observable<ServiceOrder>, onSuccess: () => void): void {
    this.busy.set(true);
    request.pipe(finalize(() => this.busy.set(false))).subscribe({
      next: (updated) => {
        this.order.set(updated);
        onSuccess();
      },
      error: (error: unknown) => this.toast.error(this.describeError(error)),
    });
  }

  private describeError(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      const first = Object.values(error.error?.errors ?? {})[0] as string[] | undefined;
      return first?.[0] ?? error.error?.message ?? 'Não foi possível concluir a ação.';
    }
    return 'Não foi possível concluir a ação.';
  }

  /** Para o diálogo de status: opções que exigem orçamento aprovado. */
  protected readonly blockedStatuses = computed<ServiceOrderStatus[]>(() =>
    this.approved() ? [] : ['in_progress', 'waiting_parts', 'completed', 'delivered'],
  );
}
