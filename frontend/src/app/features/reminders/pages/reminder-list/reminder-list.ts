import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, finalize, of, switchMap, tap } from 'rxjs';

import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { Pagination } from '../../../../shared/components/pagination/pagination';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { formatMileage, formatPlate } from '../../../../shared/utils/br-format';
import { ReminderFilter, ReminderPage, RemindersService, ReminderStatus, ServiceReminder } from '../../reminders.service';

/**
 * Clientes a contatar para refazer serviços periódicos (troca de óleo, revisão...).
 * A lista é montada todo dia pelo scheduler a partir do intervalo cadastrado em cada serviço.
 */
@Component({
  selector: 'app-reminder-list',
  imports: [DatePipe, Icon, Pagination, BrFormatPipe],
  templateUrl: './reminder-list.html',
  styleUrls: ['../../../services/pages/service-list/service-list.scss', './reminder-list.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReminderList {
  private readonly reminders = inject(RemindersService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  protected readonly filters: { value: ReminderFilter; label: string }[] = [
    { value: 'open', label: 'A contatar' },
    { value: 'scheduled', label: 'Agendados' },
    { value: 'dismissed', label: 'Descartados' },
    { value: 'all', label: 'Todos' },
  ];

  protected readonly search = signal('');
  protected readonly status = signal<ReminderFilter>('open');
  protected readonly page = signal(1);
  private readonly tick = signal(0);

  protected readonly result = signal<ReminderPage | null>(null);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);
  protected readonly refreshing = signal(false);
  protected readonly busy = signal<number | null>(null);

  private readonly query = computed(() => ({ search: this.search().trim(), status: this.status(), page: this.page(), tick: this.tick() }));

  constructor() {
    toObservable(this.query)
      .pipe(
        debounceTime(250),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        tap(() => this.loading.set(true)),
        switchMap((query) => this.reminders.list(query).pipe(catchError(() => of(null)))),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((page) => {
        this.loading.set(false);
        this.failed.set(page === null);
        if (page) this.result.set(page);
      });
  }

  protected onSearch(value: string): void {
    this.search.set(value);
    this.page.set(1);
  }

  protected onStatus(value: ReminderFilter): void {
    this.status.set(value);
    this.page.set(1);
  }

  protected goToPage(page: number): void {
    this.page.set(page);
  }

  protected refresh(): void {
    this.refreshing.set(true);
    this.reminders
      .refresh()
      .pipe(finalize(() => this.refreshing.set(false)))
      .subscribe({
        next: ({ created }) => {
          this.toast.success(created ? `${created} ${created === 1 ? 'cliente entrou' : 'clientes entraram'} na lista.` : 'Lista atualizada. Nenhum lembrete novo.');
          this.tick.update((value) => value + 1);
        },
        error: () => this.toast.error('Não foi possível atualizar a lista.'),
      });
  }

  protected setStatus(reminder: ServiceReminder, status: ReminderStatus, message?: string): void {
    this.busy.set(reminder.id);
    this.reminders
      .update(reminder.id, status)
      .pipe(finalize(() => this.busy.set(null)))
      .subscribe({
        next: () => {
          if (message) this.toast.success(message);
          this.tick.update((value) => value + 1);
        },
        error: () => this.toast.error('Não foi possível atualizar o lembrete.'),
      });
  }

  /** Abre o WhatsApp com a mensagem pronta e marca como contatado. */
  protected contactByWhatsapp(reminder: ServiceReminder): void {
    if (reminder.status === 'pending') this.setStatus(reminder, 'contacted');
  }

  protected whatsappLink(reminder: ServiceReminder): string {
    const vehicle = `${reminder.vehicle.brand} ${reminder.vehicle.model}${reminder.vehicle.plate ? ` (${formatPlate(reminder.vehicle.plate)})` : ''}`;
    const due = reminder.due_mileage ? ` ou aos ${formatMileage(reminder.due_mileage)} km` : '';
    const text =
      `Olá, ${reminder.customer.name.split(' ')[0]}! Tudo bem? Aqui é da oficina. ` +
      `Está chegando a hora de fazer ${reminder.service_name.toLowerCase()} do seu ${vehicle}` +
      (reminder.due_at ? ` (previsto para ${new Date(reminder.due_at + 'T12:00:00').toLocaleDateString('pt-BR')}${due})` : due ? ` (previsto${due})` : '') +
      '. Quer agendar um horário?';
    return `https://wa.me/55${reminder.customer.phone}?text=${encodeURIComponent(text)}`;
  }

  /** Abre a agenda já com cliente, veículo e motivo preenchidos. */
  protected schedule(reminder: ServiceReminder): void {
    this.router.navigate(['/agenda'], {
      queryParams: {
        customer_id: reminder.customer.id,
        vehicle_id: reminder.vehicle.id,
        notes: reminder.service_name,
        reminder_id: reminder.id,
      },
    });
  }
}
