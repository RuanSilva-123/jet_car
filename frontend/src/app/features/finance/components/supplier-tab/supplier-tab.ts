import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, OnInit, output, signal, viewChild } from '@angular/core';
import { debounceTime, finalize, Subject } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { Paginated } from '../../../../core/http/api';
import { AuthService } from '../../../../core/auth/services/auth.service';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { MoneyPipe } from '../../../../shared/pipes/money.pipe';
import { formatBr } from '../../../../shared/utils/br-format';
import { PayablesService, Supplier } from '../../payables.service';

type Field = 'name' | 'document' | 'phone' | 'email' | 'contact_name' | 'notes';

/** Cadastro de fornecedores (aba de Contas a pagar). Excluir é só para o master. */
@Component({
  selector: 'app-supplier-tab',
  imports: [Icon, Pagination, ConfirmDialog, BrFormatPipe, MoneyPipe],
  templateUrl: './supplier-tab.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss', '../../pages/bills/bills-shared.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SupplierTab implements OnInit {
  private readonly payables = inject(PayablesService);
  private readonly toast = inject(ToastService);
  protected readonly isMaster = inject(AuthService).isMaster;

  /** A lista mudou: a aba de contas recarrega o seletor de fornecedores. */
  readonly changed = output<void>();
  /** Ver as contas deste fornecedor. */
  readonly showBills = output<Supplier>();

  protected readonly page = signal<Paginated<Supplier> | null>(null);
  protected readonly loading = signal(true);
  protected readonly search = signal('');
  protected readonly pageNumber = signal(1);

  protected readonly editing = signal<Supplier | null>(null);
  protected readonly formOpen = signal(false);
  protected readonly form = signal<Record<Field, string>>(this.blank());
  protected readonly errors = signal<Partial<Record<Field, string>>>({});
  protected readonly saving = signal(false);

  protected readonly pendingDelete = signal<Supplier | null>(null);
  protected readonly deleting = signal(false);

  protected readonly title = computed(() => (this.editing() ? 'Editar fornecedor' : 'Novo fornecedor'));

  private readonly search$ = new Subject<string>();
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    this.search$.pipe(debounceTime(250), takeUntilDestroyed()).subscribe((term) => {
      this.search.set(term);
      this.pageNumber.set(1);
      this.load();
    });
    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.formOpen() && !dialog.open) dialog.showModal();
      else if (!this.formOpen() && dialog.open) dialog.close();
    });
  }

  ngOnInit(): void {
    this.load();
  }

  protected onSearch(term: string): void {
    this.search$.next(term.trim());
  }

  protected goTo(page: number): void {
    this.pageNumber.set(page);
    this.load();
  }

  protected create(): void {
    this.editing.set(null);
    this.form.set(this.blank());
    this.errors.set({});
    this.formOpen.set(true);
  }

  protected edit(supplier: Supplier): void {
    this.editing.set(supplier);
    this.form.set({
      name: supplier.name,
      document: formatBr(supplier.document ?? '', 'document'),
      phone: formatBr(supplier.phone ?? '', 'phone'),
      email: supplier.email ?? '',
      contact_name: supplier.contact_name ?? '',
      notes: supplier.notes ?? '',
    });
    this.errors.set({});
    this.formOpen.set(true);
  }

  protected set(field: Field, value: string, format?: 'document' | 'phone'): void {
    this.form.update((form) => ({ ...form, [field]: format ? formatBr(value, format) : value }));
  }

  protected save(): void {
    if (this.saving()) return;
    if (!this.form().name.trim()) {
      this.errors.set({ name: 'Informe o nome do fornecedor.' });
      return;
    }

    this.saving.set(true);
    this.payables
      .saveSupplier(this.editing()?.id ?? null, this.form())
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: () => {
          this.toast.success(this.editing() ? 'Fornecedor atualizado.' : 'Fornecedor cadastrado.');
          this.formOpen.set(false);
          this.changed.emit();
          this.load();
        },
        error: (error: unknown) => {
          if (error instanceof HttpErrorResponse && error.status === 422) {
            const raw = (error.error?.errors ?? {}) as Record<string, string[]>;
            this.errors.set(Object.fromEntries(Object.entries(raw).map(([key, messages]) => [key, messages[0]])));
            return;
          }
          this.toast.error('Não foi possível salvar o fornecedor.');
        },
      });
  }

  protected confirmDelete(): void {
    const supplier = this.pendingDelete();
    if (!supplier) return;
    this.deleting.set(true);
    this.payables
      .deleteSupplier(supplier.id)
      .pipe(finalize(() => this.deleting.set(false)))
      .subscribe({
        next: () => {
          this.pendingDelete.set(null);
          this.toast.success('Fornecedor excluído. As contas dele continuam no histórico.');
          this.changed.emit();
          this.load();
        },
        error: () => {
          this.pendingDelete.set(null);
          this.toast.error('Não foi possível excluir o fornecedor.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.formOpen.set(false);
  }

  private load(): void {
    this.loading.set(true);
    this.payables
      .suppliers({ search: this.search(), page: this.pageNumber() })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({ next: (page) => this.page.set(page), error: () => this.toast.error('Não foi possível carregar os fornecedores.') });
  }

  private blank(): Record<Field, string> {
    return { name: '', document: '', phone: '', email: '', contact_name: '', notes: '' };
  }
}
