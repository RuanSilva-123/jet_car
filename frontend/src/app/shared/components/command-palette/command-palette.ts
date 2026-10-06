import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, of, Subject, switchMap, tap } from 'rxjs';

import { SearchResults, SearchService } from '../../../core/search/search.service';
import { formatDocument, formatPhone, formatPlate } from '../../utils/br-format';
import { Icon, IconName } from '../icon/icon';

export interface PaletteAction {
  group: string;
  icon: IconName;
  title: string;
  subtitle?: string;
  badge?: string;
  route: (string | number)[];
}

/** Atalhos mostrados antes de digitar. */
export const QUICK_ACTIONS: PaletteAction[] = [
  { group: 'Atalhos', icon: 'plus', title: 'Nova ordem de serviço', route: ['/service-orders', 'new'] },
  { group: 'Atalhos', icon: 'calendar', title: 'Agenda', route: ['/agenda'] },
  { group: 'Atalhos', icon: 'clipboard', title: 'Ordens de serviço', route: ['/service-orders'] },
  { group: 'Atalhos', icon: 'contact', title: 'Clientes', route: ['/customers'] },
  { group: 'Atalhos', icon: 'boxes', title: 'Estoque de peças', route: ['/parts'] },
  { group: 'Atalhos', icon: 'bell', title: 'Lembretes de revisão', route: ['/reminders'] },
];

/**
 * Transforma o resultado da busca em ações. Placa encontrada: primeiro a OS em aberto do veículo,
 * depois o histórico e o cliente — o caminho mais comum no balcão.
 */
export function toActions(results: SearchResults): PaletteAction[] {
  const actions: PaletteAction[] = [];
  const seen = new Set<string>();
  const push = (action: PaletteAction) => {
    const key = action.route.join('/');
    if (seen.has(key)) return;
    seen.add(key);
    actions.push(action);
  };

  for (const vehicle of results.vehicles) {
    const label = `${vehicle.brand} ${vehicle.model}`;
    const plate = vehicle.plate ? formatPlate(vehicle.plate) : 'Sem placa';
    if (vehicle.active_order) {
      push({
        group: 'Veículos',
        icon: 'clipboard',
        title: `OS #${vehicle.active_order.number} em aberto · ${plate}`,
        subtitle: `${label} · ${vehicle.customer.name}`,
        badge: vehicle.active_order.status_label,
        route: ['/service-orders', vehicle.active_order.id],
      });
    }
    push({
      group: 'Veículos',
      icon: 'car',
      title: `${plate} · ${label}`,
      subtitle: `Histórico do veículo · ${vehicle.customer.name}`,
      route: ['/vehicles', vehicle.id, 'history'],
    });
  }

  for (const customer of results.customers) {
    push({
      group: 'Clientes',
      icon: 'user',
      title: customer.name,
      subtitle: [customer.document ? formatDocument(customer.document) : null, formatPhone(customer.phone)].filter(Boolean).join(' · '),
      route: ['/customers', customer.id, 'edit'],
    });
  }
  // Dono do veículo encontrado pela placa, se ainda não apareceu
  for (const vehicle of results.vehicles) {
    push({ group: 'Clientes', icon: 'user', title: vehicle.customer.name, subtitle: 'Cadastro do cliente', route: ['/customers', vehicle.customer.id, 'edit'] });
  }

  for (const order of results.orders) {
    push({
      group: 'Ordens de serviço',
      icon: 'clipboard',
      title: `OS #${order.number} · ${order.customer}`,
      subtitle: `${order.vehicle}${order.plate ? ` · ${formatPlate(order.plate)}` : ''}`,
      badge: order.status_label,
      route: ['/service-orders', order.id],
    });
  }

  return actions;
}

/** Busca global do painel (Ctrl+K / ⌘K). Teclado: ↑ ↓ Enter Esc. */
@Component({
  selector: 'app-command-palette',
  imports: [Icon],
  templateUrl: './command-palette.html',
  styleUrl: './command-palette.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CommandPalette {
  private readonly searchService = inject(SearchService);
  private readonly router = inject(Router);

  readonly open = input(false);
  readonly closed = output<void>();

  protected readonly query = signal('');
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly results = signal<PaletteAction[]>([]);
  protected readonly active = signal(0);

  /** Sem texto: atalhos. Com texto: resultados da busca. */
  protected readonly actions = computed(() => (this.query().trim().length < 2 ? QUICK_ACTIONS : this.results()));
  protected readonly groups = computed(() => {
    const groups: { name: string; items: { action: PaletteAction; index: number }[] }[] = [];
    this.actions().forEach((action, index) => {
      let group = groups.find((item) => item.name === action.group);
      if (!group) groups.push((group = { name: action.group, items: [] }));
      group.items.push({ action, index });
    });
    return groups;
  });

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly field = viewChild.required<ElementRef<HTMLInputElement>>('field');
  private readonly search$ = new Subject<string>();

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.query.set('');
        this.results.set([]);
        this.active.set(0);
        dialog.showModal();
        setTimeout(() => this.field().nativeElement.focus());
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });

    this.search$
      .pipe(
        debounceTime(200),
        distinctUntilChanged(),
        tap(() => this.failed.set(false)),
        switchMap((q) => {
          if (q.length < 2) return of(null);
          this.loading.set(true);
          return this.searchService.search(q).pipe(
            catchError(() => {
              this.failed.set(true);
              return of(null);
            }),
          );
        }),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((results) => {
        this.loading.set(false);
        this.results.set(results ? toActions(results) : []);
        this.active.set(0);
      });
  }

  protected onQuery(value: string): void {
    this.query.set(value);
    this.search$.next(value.trim());
  }

  protected onKeydown(event: KeyboardEvent): void {
    const total = this.actions().length;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!total) return;
      const step = event.key === 'ArrowDown' ? 1 : -1;
      this.active.update((index) => (index + step + total) % total);
      queueMicrotask(() => document.getElementById(`palette-item-${this.active()}`)?.scrollIntoView({ block: 'nearest' }));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const action = this.actions()[this.active()];
      if (action) this.go(action);
    }
  }

  protected go(action: PaletteAction): void {
    this.closed.emit();
    this.router.navigate(action.route);
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    this.closed.emit();
  }

  protected onBackdropClick(event: MouseEvent): void {
    if (event.target === this.dialog().nativeElement) this.closed.emit();
  }
}
