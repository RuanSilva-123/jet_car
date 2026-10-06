import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, input, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { debounceTime, distinctUntilChanged, finalize, Subject, switchMap } from 'rxjs';

import { ValidationErrorBody } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { Combobox, ComboboxOption } from '../../../../shared/components/combobox/combobox';
import { Icon } from '../../../../shared/components/icon/icon';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { centsToInput, formatQuantity, maskMoney, maskQuantity, moneyToCents, parseQuantity } from '../../../../shared/utils/br-format';
import { ServiceFormDialog } from '../../../services/components/service-form-dialog/service-form-dialog';
import { LaborService } from '../../../services/models/labor-service';
import { LaborServicesService } from '../../../services/services/labor-services.service';
import { ServiceOrder, ServiceOrderBudgetPayload } from '../../models/service-order';
import { ServiceOrdersService } from '../../services/service-orders.service';

/** Serviço na OS: existente tem id; novo tem labor_service_id. Valor como digitado ("250,00"); vazio = a definir. */
interface ItemDraft {
  key: number;
  id: number | null;
  labor_service_id: number | null;
  name: string;
  notes: string;
  price: string;
  is_done: boolean;
}

/** Peça (quantidade e valor como digitados; valor vazio = a definir). */
interface PartDraft {
  key: number;
  id: number | null;
  name: string;
  part_number: string;
  quantity: string;
  unit_price: string;
}

let nextKey = 0;

/** Valor digitado → centavos; vazio = null ("a definir"). */
function priceToCents(value: string): number | null {
  return value.trim() === '' ? null : moneyToCents(value);
}

/**
 * Montagem do orçamento: valor de cada serviço e peça já levantados no diagnóstico,
 * com espaço para incluir/remover o que faltar e dar desconto.
 */
@Component({
  selector: 'app-order-budget',
  imports: [RouterLink, Icon, Combobox, BrFormatPipe, MoneyPipe, ServiceFormDialog],
  templateUrl: './order-budget.html',
  styleUrl: './order-budget.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrderBudget implements OnInit {
  private readonly orders = inject(ServiceOrdersService);
  private readonly laborServices = inject(LaborServicesService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  /** :id da OS. */
  readonly id = input.required<string>();

  protected readonly order = signal<ServiceOrder | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly fieldErrors = signal<Record<string, string>>({});

  protected readonly items = signal<ItemDraft[]>([]);
  protected readonly parts = signal<PartDraft[]>([]);
  protected readonly discount = signal('');
  protected readonly serviceOptions = signal<ComboboxOption[]>([]);
  protected readonly searchingServices = signal(false);
  protected readonly newServiceOpen = signal(false);

  protected readonly laborTotal = computed(() => this.items().reduce((sum, item) => sum + moneyToCents(item.price), 0));
  protected readonly partsTotal = computed(() => this.parts().reduce((sum, part) => sum + (this.partTotal(part) ?? 0), 0));
  protected readonly subtotal = computed(() => this.laborTotal() + this.partsTotal());
  protected readonly discountCents = computed(() => moneyToCents(this.discount()));
  protected readonly total = computed(() => Math.max(0, this.subtotal() - this.discountCents()));
  protected readonly discountTooHigh = computed(() => this.discountCents() > this.subtotal());

  /** Linhas ainda sem valor (o orçamento só pode ser enviado com tudo preenchido). */
  protected readonly unpriced = computed(
    () => this.items().filter((item) => !item.price.trim()).length + this.parts().filter((part) => !part.unit_price.trim()).length,
  );
  protected readonly lineCount = computed(() => this.items().length + this.parts().length);

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
        next: (order) => {
          if (order.is_final) {
            this.toast.error(`A OS está ${order.status_label.toLowerCase()}. Reabra a OS para alterar o orçamento.`);
            this.router.navigate(['/service-orders', order.id]);
            return;
          }
          this.load(order);
        },
        error: () => {
          this.toast.error('OS não encontrada.');
          this.router.navigate(['/service-orders']);
        },
      });
  }

  // --- mão de obra ----------------------------------------------------------------

  protected onServiceQuery(search: string): void {
    this.serviceSearch$.next(search.trim());
  }

  protected addService(option: ComboboxOption): void {
    this.items.update((items) => [
      ...items,
      { key: ++nextKey, id: null, labor_service_id: Number(option.code), name: option.name, notes: '', price: '', is_done: false },
    ]);
    this.clearFieldError('items');
    // Já posiciona no valor do serviço recém-adicionado
    setTimeout(() => document.querySelector<HTMLInputElement>('.item:last-child .money-input input')?.focus());
  }

  protected onServiceCreated(service: LaborService): void {
    this.addService({ code: String(service.id), name: service.name });
  }

  protected removeItem(key: number): void {
    this.items.update((items) => items.filter((item) => item.key !== key));
  }

  protected updateItem(key: number, changes: Partial<Pick<ItemDraft, 'notes' | 'price'>>): void {
    this.items.update((items) => items.map((item) => (item.key === key ? { ...item, ...changes } : item)));
  }

  // --- peças ------------------------------------------------------------------------

  protected addPart(): void {
    this.parts.update((parts) => [...parts, { key: ++nextKey, id: null, name: '', part_number: '', quantity: '1', unit_price: '' }]);
    setTimeout(() => document.querySelector<HTMLInputElement>('.part:last-child .part__name')?.focus());
  }

  protected removePart(key: number): void {
    this.parts.update((parts) => parts.filter((part) => part.key !== key));
  }

  protected updatePart(key: number, changes: Partial<Omit<PartDraft, 'key' | 'id'>>): void {
    this.parts.update((parts) => parts.map((part) => (part.key === key ? { ...part, ...changes } : part)));
  }

  /** Total da peça; null enquanto o valor unitário não foi preenchido. */
  protected partTotal(part: PartDraft): number | null {
    return part.unit_price.trim() ? Math.round(parseQuantity(part.quantity) * moneyToCents(part.unit_price)) : null;
  }

  // --- máscaras dos campos soltos ------------------------------------------------------

  protected money(event: Event): string {
    const input = event.target as HTMLInputElement;
    input.value = maskMoney(input.value);
    return input.value;
  }

  protected quantity(event: Event): string {
    const input = event.target as HTMLInputElement;
    input.value = maskQuantity(input.value);
    return input.value;
  }

  // --- salvar ---------------------------------------------------------------------

  protected submit(): void {
    const order = this.order();
    if (!order) return;
    this.formError.set(null);

    const errors: Record<string, string> = {};
    if (this.parts().some((part) => !part.name.trim())) errors['parts'] = 'Informe o nome de todas as peças (ou remova as linhas vazias).';
    if (this.parts().some((part) => parseQuantity(part.quantity) <= 0)) errors['parts'] ??= 'Quantidade das peças deve ser maior que zero.';
    if (this.discountTooHigh()) errors['discount_cents'] = 'O desconto não pode ser maior que o valor do orçamento.';
    this.fieldErrors.set(errors);

    if (Object.keys(errors).length) {
      this.formError.set('Revise os campos destacados.');
      return;
    }

    const payload: ServiceOrderBudgetPayload = {
      items: this.items().map((item) => ({
        ...(item.id ? { id: item.id } : { labor_service_id: item.labor_service_id }),
        notes: item.notes,
        price_cents: priceToCents(item.price),
      })),
      parts: this.parts().map((part) => ({
        ...(part.id ? { id: part.id } : {}),
        name: part.name.trim(),
        part_number: part.part_number.trim(),
        quantity: parseQuantity(part.quantity),
        unit_price_cents: priceToCents(part.unit_price),
      })),
      discount_cents: this.discountCents(),
    };

    this.saving.set(true);
    this.orders
      .update(order.id, payload)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (updated) => {
          this.toast.success(
            updated.unpriced_count
              ? `Orçamento salvo. Faltam valores em ${updated.unpriced_count} ${updated.unpriced_count === 1 ? 'item' : 'itens'}.`
              : 'Orçamento pronto para enviar ao cliente.',
          );
          this.router.navigate(['/service-orders', updated.id]);
        },
        error: (error: unknown) => this.handleError(error),
      });
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  private load(order: ServiceOrder): void {
    this.order.set(order);
    this.items.set(
      (order.items ?? []).map((item) => ({
        key: ++nextKey,
        id: item.id,
        labor_service_id: item.labor_service_id,
        name: item.name,
        notes: item.notes ?? '',
        price: item.price_cents === null ? '' : centsToInput(item.price_cents),
        is_done: item.is_done,
      })),
    );
    this.parts.set(
      (order.parts ?? []).map((part) => ({
        key: ++nextKey,
        id: part.id,
        name: part.name,
        part_number: part.part_number ?? '',
        quantity: formatQuantity(part.quantity),
        unit_price: part.unit_price_cents === null ? '' : centsToInput(part.unit_price_cents),
      })),
    );
    this.discount.set(order.discount_cents ? centsToInput(order.discount_cents) : '');
  }

  private clearFieldError(field: string): void {
    this.fieldErrors.update(({ [field]: _removed, ...rest }) => rest);
  }

  private handleError(error: unknown): void {
    if (error instanceof HttpErrorResponse && error.status === 422) {
      const { errors } = error.error as ValidationErrorBody;
      const mapped: Record<string, string> = {};
      for (const [path, messages] of Object.entries(errors ?? {})) {
        // Erros de linhas (items.0.x / parts.1.x) aparecem junto da respectiva lista
        const key = path.startsWith('items.') ? 'items' : path.startsWith('parts.') ? 'parts' : path;
        mapped[key] ??= messages[0];
      }
      this.fieldErrors.set(mapped);
      this.formError.set(mapped['status'] ?? 'Revise os campos destacados.');
      return;
    }
    this.formError.set('Não foi possível salvar o orçamento. Tente novamente.');
  }
}
