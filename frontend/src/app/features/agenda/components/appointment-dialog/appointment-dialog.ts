import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { debounceTime, distinctUntilChanged, finalize, Subject, switchMap } from 'rxjs';

import { Combobox, ComboboxOption } from '../../../../shared/components/combobox/combobox';
import { Icon } from '../../../../shared/components/icon/icon';
import { formatPhone, formatPlate } from '../../../../shared/utils/br-format';
import { Customer } from '../../../customers/models/customer';
import { CustomersService } from '../../../customers/services/customers.service';
import { ServiceOrdersService } from '../../../service-orders/services/service-orders.service';
import { localDate } from '../../../finance/dates';
import { AgendaService, Appointment } from '../../agenda.service';

/** Dados para abrir o diálogo já preenchido (ex.: vindo de um lembrete de revisão). */
export interface AppointmentPrefill {
  customerId?: number;
  vehicleId?: number | null;
  notes?: string;
  reminderId?: number | null;
  date?: string;
}

const DURATIONS = [30, 60, 90, 120, 180, 240, 480];

/** Novo agendamento / edição. */
@Component({
  selector: 'app-appointment-dialog',
  imports: [Icon, Combobox],
  templateUrl: './appointment-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  styles: `
    .who .segmented {
      display: flex;

      button {
        flex: 1;
      }
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentDialog {
  private readonly agenda = inject(AgendaService);
  private readonly orders = inject(ServiceOrdersService);
  private readonly customersService = inject(CustomersService);
  private readonly destroyRef = inject(DestroyRef);

  readonly open = input(false);
  readonly appointment = input<Appointment | null>(null);
  readonly prefill = input<AppointmentPrefill | null>(null);

  readonly saved = output<Appointment>();
  readonly closed = output<void>();

  protected readonly durations = DURATIONS;
  /** Cliente cadastrado ou alguém que ainda não tem cadastro (só o nome). */
  protected readonly mode = signal<'customer' | 'guest'>('customer');
  protected readonly guestName = signal('');
  protected readonly guestPhone = signal('');
  protected readonly vehicleDescription = signal('');
  protected readonly customer = signal<Customer | null>(null);
  protected readonly customerOptions = signal<ComboboxOption[]>([]);
  protected readonly searching = signal(false);
  protected readonly vehicleId = signal<number | null>(null);
  protected readonly date = signal('');
  protected readonly time = signal('08:00');
  protected readonly duration = signal(60);
  protected readonly notes = signal('');
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly isEdit = computed(() => this.appointment() !== null);
  protected readonly vehicles = computed(() => this.customer()?.vehicles ?? []);

  private customers: Customer[] = [];
  private reminderId: number | null = null;
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly search$ = new Subject<string>();

  constructor() {
    this.search$
      .pipe(
        debounceTime(250),
        distinctUntilChanged(),
        switchMap((search) => {
          this.searching.set(true);
          return this.orders.searchCustomers(search).pipe(finalize(() => this.searching.set(false)));
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((customers) => {
        this.customers = customers;
        this.customerOptions.set(
          customers.map((customer) => ({
            code: String(customer.id),
            name: [customer.trade_name || customer.name, ...(customer.vehicles ?? []).map((v) => v.plate && formatPlate(v.plate))]
              .filter(Boolean)
              .join(' · '),
          })),
        );
      });

    effect(() => {
      const dialog = this.dialog().nativeElement;
      if (this.open() && !dialog.open) {
        this.reset();
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected onQuery(search: string): void {
    this.search$.next(search.trim());
  }

  protected onGuestPhone(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = formatPhone(element.value);
    this.guestPhone.set(element.value);
  }

  protected setMode(mode: 'customer' | 'guest'): void {
    this.mode.set(mode);
    this.error.set(null);
    if (mode === 'guest') setTimeout(() => document.getElementById('appointment-guest-name')?.focus());
  }

  protected selectCustomer(option: ComboboxOption): void {
    const customer = this.customers.find((item) => String(item.id) === option.code) ?? null;
    this.customer.set(customer);
    this.vehicleId.set(customer?.vehicles?.length === 1 ? customer.vehicles[0].id : null);
  }

  protected submit(): void {
    const customer = this.customer();
    const guest = this.mode() === 'guest';
    if (guest && !this.guestName().trim()) {
      this.error.set('Digite o nome de quem vem.');
      return;
    }
    if (!guest && !customer) {
      this.error.set('Selecione o cliente ou use "Sem cadastro".');
      return;
    }
    if (!this.date() || !this.time()) {
      this.error.set('Informe a data e o horário.');
      return;
    }

    const payload = {
      customer_id: guest ? null : customer!.id,
      vehicle_id: guest ? null : this.vehicleId(),
      contact_name: guest ? this.guestName().trim() : '',
      contact_phone: guest ? this.guestPhone() : '',
      vehicle_description: guest || !this.vehicleId() ? this.vehicleDescription().trim() : '',
      // Horário local do navegador → ISO com fuso
      scheduled_at: new Date(`${this.date()}T${this.time()}:00`).toISOString(),
      duration_minutes: this.duration(),
      notes: this.notes(),
      ...(this.isEdit() ? {} : { service_reminder_id: this.reminderId }),
    };

    const current = this.appointment();
    this.error.set(null);
    this.saving.set(true);
    (current ? this.agenda.update(current.id, payload) : this.agenda.create(payload))
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (appointment) => this.saved.emit(appointment),
        error: (error: unknown) => {
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? 'Não foi possível salvar o agendamento.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }

  private reset(): void {
    const appointment = this.appointment();
    const prefill = this.prefill();
    this.error.set(null);
    this.customer.set(null);
    this.customerOptions.set([]);
    this.mode.set(appointment && !appointment.customer ? 'guest' : 'customer');
    this.guestName.set(appointment?.contact?.name ?? '');
    this.guestPhone.set(formatPhone(appointment?.contact?.phone ?? ''));
    this.vehicleDescription.set(appointment?.vehicle_description ?? '');
    this.reminderId = prefill?.reminderId ?? null;

    if (appointment) {
      const start = new Date(appointment.scheduled_at);
      this.date.set(localDate(start));
      this.time.set(start.toTimeString().slice(0, 5));
      this.duration.set(appointment.duration_minutes);
      this.notes.set(appointment.notes ?? '');
      this.vehicleId.set(appointment.vehicle?.id ?? null);
      if (appointment.customer) this.loadCustomer(appointment.customer.id, appointment.vehicle?.id ?? null);
      return;
    }

    this.date.set(prefill?.date ?? localDate(new Date(Date.now() + 86400000)));
    this.time.set('08:00');
    this.duration.set(60);
    this.notes.set(prefill?.notes ?? '');
    this.vehicleId.set(prefill?.vehicleId ?? null);
    if (prefill?.customerId) this.loadCustomer(prefill.customerId, prefill.vehicleId ?? null);
  }

  private loadCustomer(id: number, vehicleId: number | null): void {
    this.customersService.get(id).subscribe({
      next: (customer) => {
        this.customers = [customer];
        this.customerOptions.set([{ code: String(customer.id), name: customer.trade_name || customer.name }]);
        this.customer.set(customer);
        this.vehicleId.set(vehicleId ?? (customer.vehicles?.length === 1 ? customer.vehicles[0].id : null));
      },
    });
  }
}
