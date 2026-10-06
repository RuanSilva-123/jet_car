import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { debounceTime, distinctUntilChanged, finalize, Subject, switchMap } from 'rxjs';

import { Combobox, ComboboxOption } from '../../../../shared/components/combobox/combobox';
import { Icon } from '../../../../shared/components/icon/icon';
import { formatMileage, formatPhone, formatPlate } from '../../../../shared/utils/br-format';
import { Customer, Vehicle, VEHICLE_TYPES, VehicleType } from '../../../customers/models/customer';
import { CustomersService } from '../../../customers/services/customers.service';
import { ServiceOrdersService } from '../../../service-orders/services/service-orders.service';
import { AgendaService, Appointment, CheckInPayload } from '../../agenda.service';

/** "new" = cadastrar um carro novo no check-in. */
type VehicleChoice = number | 'new' | null;

/**
 * O carro chegou: confirma cliente, veículo e km e abre a OS.
 * Quem foi agendado sem cadastro vira cliente aqui (cadastro rápido ou cliente já existente).
 */
@Component({
  selector: 'app-check-in-dialog',
  imports: [Icon, Combobox],
  templateUrl: './check-in-dialog.html',
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  styles: `
    .who .segmented {
      display: flex;

      button {
        flex: 1;
      }
    }

    .section-title {
      margin-top: 4px;
      color: var(--color-text-muted);
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CheckInDialog {
  private readonly agenda = inject(AgendaService);
  private readonly customers = inject(CustomersService);
  private readonly orders = inject(ServiceOrdersService);

  readonly open = input(false);
  readonly appointment = input<Appointment | null>(null);

  /** Id da OS aberta. */
  readonly checkedIn = output<number>();
  readonly closed = output<void>();

  protected readonly vehicleTypes = VEHICLE_TYPES;

  /** Agendado sem cadastro. */
  protected readonly isGuest = computed(() => !!this.appointment() && !this.appointment()!.customer);
  /** Sem cadastro: cadastrar agora ou escolher um cliente que já existe. */
  protected readonly guestMode = signal<'new' | 'existing'>('new');
  protected readonly name = signal('');
  protected readonly phone = signal('');
  protected readonly whatsapp = signal(true);

  protected readonly customerOptions = signal<ComboboxOption[]>([]);
  protected readonly searching = signal(false);
  protected readonly chosenCustomer = signal<Customer | null>(null);

  protected readonly vehicles = signal<Vehicle[]>([]);
  protected readonly vehicleChoice = signal<VehicleChoice>(null);
  protected readonly vehicleType = signal<VehicleType>('car');
  protected readonly brand = signal('');
  protected readonly model = signal('');
  protected readonly plate = signal('');

  protected readonly mileage = signal('');
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  /** Precisa escolher/cadastrar o carro (o agendamento não tinha um do cadastro). */
  protected readonly needsVehicle = computed(() => !this.appointment()?.vehicle);
  /** Cliente novo não tem carros: só dá para cadastrar um. */
  protected readonly onlyNewVehicle = computed(() => this.isGuest() && this.guestMode() === 'new');
  protected readonly showVehicleForm = computed(() => this.onlyNewVehicle() || this.vehicleChoice() === 'new');

  private found: Customer[] = [];
  private readonly search$ = new Subject<string>();
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    this.search$
      .pipe(
        debounceTime(250),
        distinctUntilChanged(),
        switchMap((search) => {
          this.searching.set(true);
          return this.orders.searchCustomers(search).pipe(finalize(() => this.searching.set(false)));
        }),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((customers) => {
        this.found = customers;
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
      const appointment = this.appointment();
      if (this.open() && appointment && !dialog.open) {
        this.reset(appointment);
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected setGuestMode(mode: 'new' | 'existing'): void {
    this.guestMode.set(mode);
    this.error.set(null);
    this.chosenCustomer.set(null);
    this.vehicles.set([]);
    this.vehicleChoice.set(mode === 'new' ? 'new' : null);
  }

  protected onQuery(search: string): void {
    this.search$.next(search.trim());
  }

  protected selectCustomer(option: ComboboxOption): void {
    const customer = this.found.find((item) => String(item.id) === option.code) ?? null;
    this.chosenCustomer.set(customer);
    this.setVehicles(customer?.vehicles ?? []);
  }

  protected onVehicleChoice(value: string): void {
    this.vehicleChoice.set(value === 'new' ? 'new' : value ? Number(value) : null);
  }

  protected onPhone(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = formatPhone(element.value);
    this.phone.set(element.value);
  }

  protected onPlate(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = formatPlate(element.value);
    this.plate.set(element.value);
  }

  protected onMileage(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = formatMileage(element.value);
    this.mileage.set(element.value);
  }

  protected submit(): void {
    const appointment = this.appointment();
    if (!appointment || this.saving()) return;

    const km = this.mileage().replace(/\D/g, '');
    const payload: CheckInPayload = { mileage: km ? Number(km) : null };

    if (this.isGuest()) {
      if (this.guestMode() === 'new') {
        if (!this.name().trim() || this.phone().replace(/\D/g, '').length < 10) {
          this.error.set('Informe o nome e o telefone (com DDD) para cadastrar o cliente.');
          return;
        }
        payload.customer = { name: this.name().trim(), phone: this.phone(), phone_is_whatsapp: this.whatsapp() };
      } else {
        const customer = this.chosenCustomer();
        if (!customer) {
          this.error.set('Escolha o cliente.');
          return;
        }
        payload.customer_id = customer.id;
      }
    }

    if (this.needsVehicle()) {
      if (this.showVehicleForm()) {
        if (!this.brand().trim() || !this.model().trim()) {
          this.error.set('Informe a marca e o modelo do carro.');
          return;
        }
        payload.vehicle = { type: this.vehicleType(), brand: this.brand().trim(), model: this.model().trim(), plate: this.plate() };
      } else if (typeof this.vehicleChoice() === 'number') {
        payload.vehicle_id = this.vehicleChoice() as number;
      } else {
        this.error.set('Selecione o veículo que chegou ou cadastre um novo.');
        return;
      }
    }

    this.error.set(null);
    this.saving.set(true);
    this.agenda
      .checkIn(appointment.id, payload)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (orderId) => this.checkedIn.emit(orderId),
        error: (error: unknown) => {
          const first = error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? 'Não foi possível abrir a OS.');
        },
      });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    if (!this.saving()) this.closed.emit();
  }

  private reset(appointment: Appointment): void {
    this.error.set(null);
    this.mileage.set('');
    this.guestMode.set('new');
    this.name.set(appointment.contact?.name ?? '');
    this.phone.set(formatPhone(appointment.contact?.phone ?? ''));
    this.whatsapp.set(true);
    this.chosenCustomer.set(null);
    this.customerOptions.set([]);
    this.vehicles.set([]);
    this.vehicleType.set('car');
    this.brand.set('');
    // O carro digitado no agendamento vira o modelo (a pessoa ajusta marca/modelo)
    this.model.set(appointment.vehicle_description ?? '');
    this.plate.set('');
    this.vehicleChoice.set(appointment.customer ? null : 'new');

    if (appointment.customer && !appointment.vehicle) {
      this.customers.get(appointment.customer.id).subscribe((customer) => this.setVehicles(customer.vehicles ?? []));
    }
  }

  private setVehicles(vehicles: Vehicle[]): void {
    this.vehicles.set(vehicles);
    // Um carro só: já vem escolhido; nenhum: cadastra um novo
    this.vehicleChoice.set(vehicles.length === 1 ? vehicles[0].id : vehicles.length === 0 ? 'new' : null);
  }
}
