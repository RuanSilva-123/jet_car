import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { localDate } from '../../../finance/dates';
import { AgendaService, Appointment, APPOINTMENT_STATUS, AppointmentStatus } from '../../agenda.service';
import { AppointmentDialog, AppointmentPrefill } from '../../components/appointment-dialog/appointment-dialog';
import { CheckInDialog } from '../../components/check-in-dialog/check-in-dialog';

const DAY = 86400000;

/** Segunda-feira da semana de `date`, à meia-noite local. */
function startOfWeek(date: Date): Date {
  const day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const offset = (day.getDay() + 6) % 7;
  day.setDate(day.getDate() - offset);
  return day;
}

/**
 * Agenda da semana. Agendamento vira OS no "Carro chegou". Aceita ?customer_id, ?vehicle_id,
 * ?notes e ?reminder_id para abrir o cadastro já preenchido (lembrete de revisão).
 */
@Component({
  selector: 'app-agenda',
  imports: [DatePipe, RouterLink, Icon, BrFormatPipe, AppointmentDialog, CheckInDialog],
  templateUrl: './agenda.html',
  styleUrl: './agenda.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AgendaPage implements OnInit {
  private readonly agenda = inject(AgendaService);
  private readonly toast = inject(ToastService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly statusMeta = APPOINTMENT_STATUS;
  protected readonly weekStart = signal(startOfWeek(new Date()));
  protected readonly appointments = signal<Appointment[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal<number | null>(null);
  protected readonly showClosed = signal(false);

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<Appointment | null>(null);
  protected readonly prefill = signal<AppointmentPrefill | null>(null);
  protected readonly checkingIn = signal<Appointment | null>(null);

  protected readonly today = localDate(new Date());
  protected readonly weekEnd = computed(() => new Date(this.weekStart().getTime() + 6 * DAY));

  protected readonly days = computed(() => {
    const start = this.weekStart();
    const list = this.appointments().filter((item) => this.showClosed() || item.status !== 'canceled');
    return Array.from({ length: 7 }, (_, index) => {
      const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
      const key = localDate(date);
      return { date, key, isToday: key === this.today, items: list.filter((item) => localDate(new Date(item.scheduled_at)) === key) };
    });
  });

  protected readonly total = computed(() => this.appointments().filter((item) => item.status !== 'canceled').length);

  ngOnInit(): void {
    this.load();

    const params = this.route.snapshot.queryParamMap;
    if (params.get('customer_id')) {
      this.openCreate({
        customerId: Number(params.get('customer_id')),
        vehicleId: params.get('vehicle_id') ? Number(params.get('vehicle_id')) : null,
        notes: params.get('notes') ?? '',
        reminderId: params.get('reminder_id') ? Number(params.get('reminder_id')) : null,
      });
      // Limpa a URL: recarregar a página não abre o diálogo de novo
      this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });
    }
  }

  protected shiftWeek(weeks: number): void {
    const start = this.weekStart();
    this.weekStart.set(new Date(start.getFullYear(), start.getMonth(), start.getDate() + weeks * 7));
    this.load();
  }

  protected goToToday(): void {
    this.weekStart.set(startOfWeek(new Date()));
    this.load();
  }

  protected openCreate(prefill: AppointmentPrefill | null = null): void {
    this.editing.set(null);
    this.prefill.set(prefill);
    this.formOpen.set(true);
  }

  protected openCreateOn(dayKey: string): void {
    this.openCreate({ date: dayKey < this.today ? this.today : dayKey });
  }

  protected openEdit(appointment: Appointment): void {
    this.editing.set(appointment);
    this.prefill.set(null);
    this.formOpen.set(true);
  }

  protected onSaved(appointment: Appointment): void {
    this.formOpen.set(false);
    this.toast.success(this.editing() ? 'Agendamento atualizado.' : 'Agendamento criado.');
    // Leva para a semana do agendamento
    this.weekStart.set(startOfWeek(new Date(appointment.scheduled_at)));
    this.load();
  }

  protected setStatus(appointment: Appointment, status: AppointmentStatus): void {
    this.busy.set(appointment.id);
    this.agenda
      .changeStatus(appointment.id, status)
      .pipe(finalize(() => this.busy.set(null)))
      .subscribe({
        next: (updated) => {
          this.appointments.update((list) => list.map((item) => (item.id === updated.id ? updated : item)));
          this.toast.success(`Agendamento: ${updated.status_label.toLowerCase()}.`);
        },
        error: (error: unknown) => this.toast.error(this.describeError(error)),
      });
  }

  protected onCheckedIn(orderId: number): void {
    this.checkingIn.set(null);
    this.toast.success('OS aberta. Faça a vistoria de entrada.');
    this.router.navigate(['/service-orders', orderId]);
  }

  protected whatsappLink(appointment: Appointment): string {
    const when = new Date(appointment.scheduled_at);
    const text =
      `Olá, ${appointment.customer.name.split(' ')[0]}! Confirmando seu horário na oficina: ` +
      `${when.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: '2-digit' })} às ` +
      `${when.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}` +
      (appointment.vehicle ? ` com o ${appointment.vehicle.brand} ${appointment.vehicle.model}` : '') +
      '. Podemos confirmar?';
    return `https://wa.me/55${appointment.customer.phone}?text=${encodeURIComponent(text)}`;
  }

  protected isOpen(appointment: Appointment): boolean {
    return appointment.status === 'scheduled' || appointment.status === 'confirmed';
  }

  private load(): void {
    const start = this.weekStart();
    this.loading.set(true);
    this.agenda
      .list(start, new Date(start.getFullYear(), start.getMonth(), start.getDate() + 7))
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (list) => this.appointments.set(list),
        error: () => this.toast.error('Não foi possível carregar a agenda.'),
      });
  }

  private describeError(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      const first = Object.values(error.error?.errors ?? {})[0] as string[] | undefined;
      return first?.[0] ?? error.error?.message ?? 'Não foi possível concluir a ação.';
    }
    return 'Não foi possível concluir a ação.';
  }
}
