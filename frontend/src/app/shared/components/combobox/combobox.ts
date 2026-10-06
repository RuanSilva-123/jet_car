import { ChangeDetectionStrategy, Component, computed, ElementRef, input, output, signal, viewChild } from '@angular/core';

import { Icon } from '../icon/icon';

export interface ComboboxOption {
  code: string;
  name: string;
}

/** Remove acentos e caixa para comparar: "Câmbio" → "cambio". */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const MAX_VISIBLE = 80;
let nextId = 0;

/**
 * Seleção com busca para listas grandes (ex.: centenas de modelos da FIPE).
 * Filtra por todas as palavras digitadas, sem diferenciar acentos. Teclado: ↑ ↓ Enter Esc.
 */
@Component({
  selector: 'app-combobox',
  imports: [Icon],
  templateUrl: './combobox.html',
  styleUrl: './combobox.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Combobox {
  readonly options = input<ComboboxOption[]>([]);
  /** Código da opção selecionada. */
  readonly value = input<string | null>(null);
  /** Texto exibido quando há valor mas as opções ainda não carregaram (edição). */
  readonly fallbackLabel = input<string | null>(null);
  readonly placeholder = input('Selecione');
  readonly loading = input(false);
  readonly disabled = input(false);
  readonly invalid = input(false);
  readonly inputId = input(`combobox-${nextId++}`);
  /** Busca feita fora (na API): a lista não é filtrada aqui e cada digitação emite queryChange. */
  readonly remote = input(false);
  /** Texto mostrado quando a busca não encontra nada. */
  readonly emptyText = input('Nenhum resultado');

  readonly selected = output<ComboboxOption>();
  readonly queryChange = output<string>();

  protected readonly listId = computed(() => `${this.inputId()}-list`);
  protected readonly open = signal(false);
  protected readonly query = signal('');
  protected readonly activeIndex = signal(0);

  private readonly inputRef = viewChild.required<ElementRef<HTMLInputElement>>('field');

  protected readonly selectedLabel = computed(() => {
    const code = this.value();
    if (!code) return '';
    return this.options().find((option) => option.code === code)?.name ?? this.fallbackLabel() ?? '';
  });

  private readonly matches = computed(() => {
    const terms = normalize(this.query()).split(/\s+/).filter(Boolean);
    if (this.remote() || terms.length === 0) return this.options();
    return this.options().filter((option) => {
      const name = normalize(option.name);
      return terms.every((term) => name.includes(term));
    });
  });

  protected readonly visible = computed(() => this.matches().slice(0, MAX_VISIBLE));
  protected readonly hiddenCount = computed(() => Math.max(0, this.matches().length - MAX_VISIBLE));

  protected openList(): void {
    if (this.disabled() || this.open()) return;
    this.query.set('');
    if (this.remote()) this.queryChange.emit('');
    this.open.set(true);

    const selectedIndex = this.options().findIndex((option) => option.code === this.value());
    this.activeIndex.set(Math.max(0, Math.min(selectedIndex, MAX_VISIBLE - 1)));
    queueMicrotask(() => this.scrollActiveIntoView());
  }

  protected closeList(): void {
    this.open.set(false);
    this.query.set('');
  }

  protected onQuery(text: string): void {
    this.query.set(text);
    if (this.remote()) this.queryChange.emit(text);
    this.activeIndex.set(0);
    this.open.set(true);
  }

  protected choose(option: ComboboxOption): void {
    this.selected.emit(option);
    this.closeList();
  }

  protected onKeydown(event: KeyboardEvent): void {
    const options = this.visible();

    switch (event.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        event.preventDefault();
        if (!this.open()) {
          this.openList();
          return;
        }
        const step = event.key === 'ArrowDown' ? 1 : -1;
        this.activeIndex.update((index) => Math.min(Math.max(index + step, 0), Math.max(options.length - 1, 0)));
        this.scrollActiveIntoView();
        return;
      }
      case 'Enter':
        if (this.open() && options[this.activeIndex()]) {
          event.preventDefault();
          this.choose(options[this.activeIndex()]);
        }
        return;
      case 'Escape':
        if (this.open()) {
          event.preventDefault();
          this.closeList();
        }
        return;
      case 'Tab':
        this.closeList();
    }
  }

  /** Clique em qualquer parte da caixa (ícone, borda) abre a lista e foca o campo. */
  protected onControlMousedown(event: MouseEvent): void {
    const input = this.inputRef().nativeElement;
    if (this.disabled() || event.target === input) return;

    event.preventDefault();
    input.focus();
    this.openList();
  }

  private scrollActiveIntoView(): void {
    queueMicrotask(() =>
      document.getElementById(`${this.listId()}-${this.activeIndex()}`)?.scrollIntoView({ block: 'nearest' }),
    );
  }
}
