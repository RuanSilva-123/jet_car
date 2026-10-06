import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, effect, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { finalize } from 'rxjs';

import { Icon } from '../../../../shared/components/icon/icon';
import { formatMileage } from '../../../../shared/utils/br-format';
import { Vehicle } from '../../../customers/models/customer';
import { CustomersService } from '../../../customers/services/customers.service';
import { AgendaService, Appointment } from '../../agenda.service';

/** O carro chegou: confirma veículo e km e abre a OS. */
@Component({
  selector: 'app-check-in-dialog',
  imports: [Icon],
  styleUrls: ['../../../../shared/styles/form-dialog.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dialog #dialog (cancel)="onCancel($event)" aria-labelledby="check-in-title">
      <form (submit)="$event.preventDefault(); submit()" novalidate>
        <header class="dialog__header">
          <span class="dialog__icon"><app-icon name="car" [size]="20" /></span>
          <div>
            <h2 id="check-in-title">Carro chegou</h2>
            <p>{{ appointment()?.customer?.name }} · abre a OS com o motivo do agendamento.</p>
          </div>
          <button type="button" class="btn btn--icon" (click)="closed.emit()" aria-label="Fechar" [disabled]="saving()">
            <app-icon name="close" [size]="18" />
          </button>
        </header>

        <div class="dialog__body">
          @if (!appointment()?.vehicle) {
            <div class="field">
              <label for="check-in-vehicle">Veículo</label>
              <div class="field__control">
                <select id="check-in-vehicle" [value]="vehicleId() ?? ''" (change)="vehicleId.set($any($event.target).value ? +$any($event.target).value : null)">
                  <option value="">Selecione</option>
                  @for (vehicle of vehicles(); track vehicle.id) {
                    <option [value]="vehicle.id" [selected]="vehicle.id === vehicleId()">{{ vehicle.brand }} {{ vehicle.model }}{{ vehicle.plate ? ' · ' + vehicle.plate : '' }}</option>
                  }
                </select>
              </div>
            </div>
          } @else {
            <div class="dialog__summary">
              <span>{{ appointment()!.vehicle!.brand }} {{ appointment()!.vehicle!.model }}</span>
              <strong>{{ appointment()!.vehicle!.plate ?? '' }}</strong>
            </div>
          }

          <div class="field">
            <label for="check-in-km">Km na entrada <span class="optional">(opcional)</span></label>
            <div class="field__control">
              <input id="check-in-km" [value]="mileage()" (input)="onMileage($event)" inputmode="numeric" placeholder="Ex.: 45.000" />
              <span class="field__suffix">km</span>
            </div>
          </div>

          @if (error(); as message) {
            <p class="dialog__alert" role="alert"><app-icon name="alert" [size]="16" /> {{ message }}</p>
          }
        </div>

        <footer class="dialog__footer">
          <button type="button" class="btn btn--ghost" (click)="closed.emit()" [disabled]="saving()">Cancelar</button>
          <button type="submit" class="btn btn--primary" [disabled]="saving()">
            @if (saving()) {
              <span class="spinner"></span>
            } @else {
              <app-icon name="clipboard" [size]="16" />
            }
            Abrir OS
          </button>
        </footer>
      </form>
    </dialog>
  `,
})
export class CheckInDialog {
  private readonly agenda = inject(AgendaService);
  private readonly customers = inject(CustomersService);

  readonly open = input(false);
  readonly appointment = input<Appointment | null>(null);

  /** Id da OS aberta. */
  readonly checkedIn = output<number>();
  readonly closed = output<void>();

  protected readonly vehicles = signal<Vehicle[]>([]);
  protected readonly vehicleId = signal<number | null>(null);
  protected readonly mileage = signal('');
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const dialog = this.dialog().nativeElement;
      const appointment = this.appointment();
      if (this.open() && appointment && !dialog.open) {
        this.mileage.set('');
        this.error.set(null);
        this.vehicleId.set(appointment.vehicle?.id ?? null);
        if (!appointment.vehicle) {
          this.customers.get(appointment.customer.id).subscribe((customer) => {
            this.vehicles.set(customer.vehicles ?? []);
            if (customer.vehicles?.length === 1) this.vehicleId.set(customer.vehicles[0].id);
          });
        }
        dialog.showModal();
      } else if (!this.open() && dialog.open) {
        dialog.close();
      }
    });
  }

  protected onMileage(event: Event): void {
    const element = event.target as HTMLInputElement;
    element.value = formatMileage(element.value);
    this.mileage.set(element.value);
  }

  protected submit(): void {
    const appointment = this.appointment();
    if (!appointment) return;
    if (!this.vehicleId()) {
      this.error.set('Selecione o veículo que chegou.');
      return;
    }

    const km = this.mileage().replace(/\D/g, '');
    this.saving.set(true);
    this.agenda
      .checkIn(appointment.id, appointment.vehicle ? null : this.vehicleId(), km ? Number(km) : null)
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
}
