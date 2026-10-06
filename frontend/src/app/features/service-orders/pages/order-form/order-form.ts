import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject, input, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { debounceTime, distinctUntilChanged, finalize, Subject, switchMap } from 'rxjs';

import { ValidationErrorBody } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { Combobox, ComboboxOption } from '../../../../shared/components/combobox/combobox';
import { Icon } from '../../../../shared/components/icon/icon';
import { MaskDirective } from '../../../../shared/directives/mask.directive';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { formatMileage, formatPhone } from '../../../../shared/utils/br-format';
import { Customer } from '../../../customers/models/customer';
import { CustomersService } from '../../../customers/services/customers.service';
import { ServiceOrder, ServiceOrderEntryPayload } from '../../models/service-order';
import { ServiceOrdersService } from '../../services/service-orders.service';

/**
 * Entrada do veículo: cliente, veículo, km e o que o cliente relatou.
 * Serviços e peças entram depois, no detalhe da OS (diagnóstico); os valores, em "Montar orçamento".
 */
@Component({
  selector: 'app-order-form',
  imports: [ReactiveFormsModule, RouterLink, Icon, Combobox, MaskDirective, BrFormatPipe],
  templateUrl: './order-form.html',
  styleUrl: './order-form.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrderForm implements OnInit {
  private readonly orders = inject(ServiceOrdersService);
  private readonly customers = inject(CustomersService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  /** :id da rota (edição). */
  readonly id = input<string>();
  /** ?customer=…&vehicle=… (abrir OS a partir do cadastro do cliente). */
  readonly customer = input<string>();
  readonly vehicle = input<string>();

  protected readonly isEdit = computed(() => this.id() !== undefined);

  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly fieldErrors = signal<Record<string, string>>({});

  // Cliente e veículo
  protected readonly customerOptions = signal<ComboboxOption[]>([]);
  protected readonly searchingCustomers = signal(false);
  protected readonly selectedCustomer = signal<Customer | null>(null);
  protected readonly loadingCustomer = signal(false);
  protected readonly selectedVehicleId = signal<number | null>(null);
  protected readonly order = signal<ServiceOrder | null>(null);

  protected readonly selectedVehicle = computed(
    () => this.selectedCustomer()?.vehicles?.find((vehicle) => vehicle.id === this.selectedVehicleId()) ?? null,
  );

  protected readonly form = inject(NonNullableFormBuilder).group({
    mileage: [''],
    expected_at: [''],
    complaint: ['', Validators.maxLength(2000)],
    notes: ['', Validators.maxLength(2000)],
  });

  protected readonly today = new Date().toISOString().slice(0, 10);

  private readonly customerSearch$ = new Subject<string>();

  ngOnInit(): void {
    this.customerSearch$
      .pipe(
        debounceTime(250),
        distinctUntilChanged(),
        switchMap((search) => {
          this.searchingCustomers.set(true);
          return this.orders.searchCustomers(search).pipe(finalize(() => this.searchingCustomers.set(false)));
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((customers) => this.customerOptions.set(customers.map((customer) => this.customerOption(customer))));

    const id = this.id();
    if (id !== undefined) {
      this.loadOrder(Number(id));
    } else if (this.customer()) {
      this.loadCustomer(Number(this.customer()), this.vehicle() ? Number(this.vehicle()) : null);
    }
  }

  // --- cliente e veículo ------------------------------------------------------

  protected onCustomerQuery(search: string): void {
    this.customerSearch$.next(search.trim());
  }

  protected selectCustomer(option: ComboboxOption): void {
    this.loadCustomer(Number(option.code), null);
  }

  protected selectVehicle(vehicleId: number): void {
    this.selectedVehicleId.set(vehicleId);
    const vehicle = this.selectedVehicle();
    // Sugere a última quilometragem conhecida do veículo
    if (vehicle?.mileage && !this.form.controls.mileage.value) {
      this.form.controls.mileage.setValue(formatMileage(vehicle.mileage));
    }
    this.clearFieldError('vehicle_id');
  }

  private loadCustomer(customerId: number, vehicleId: number | null): void {
    this.loadingCustomer.set(true);
    this.selectedVehicleId.set(null);

    this.customers
      .get(customerId)
      .pipe(finalize(() => this.loadingCustomer.set(false)))
      .subscribe({
        next: (customer) => {
          this.selectedCustomer.set(customer);
          this.customerOptions.set([this.customerOption(customer)]);
          this.clearFieldError('customer_id');

          const vehicles = customer.vehicles ?? [];
          const preselected = vehicles.find((vehicle) => vehicle.id === vehicleId) ?? (vehicles.length === 1 ? vehicles[0] : null);
          if (preselected) this.selectVehicle(preselected.id);
        },
        error: () => this.toast.error('Cliente não encontrado.'),
      });
  }

  private customerOption(customer: Customer): ComboboxOption {
    return { code: String(customer.id), name: `${customer.trade_name || customer.name} · ${formatPhone(customer.phone)}` };
  }

  // --- salvar ---------------------------------------------------------------------

  protected submit(): void {
    this.formError.set(null);

    const errors: Record<string, string> = {};
    if (!this.isEdit() && !this.selectedCustomer()) errors['customer_id'] = 'Selecione o cliente.';
    if (!this.isEdit() && this.selectedCustomer() && !this.selectedVehicleId()) errors['vehicle_id'] = 'Selecione o veículo.';
    this.fieldErrors.set(errors);

    if (Object.keys(errors).length || this.form.invalid) {
      this.formError.set('Revise os campos destacados.');
      return;
    }

    const payload: ServiceOrderEntryPayload = this.form.getRawValue();
    if (!this.isEdit()) {
      payload.customer_id = this.selectedCustomer()!.id;
      payload.vehicle_id = this.selectedVehicleId()!;
    }

    this.saving.set(true);
    const request = this.isEdit() ? this.orders.update(Number(this.id()), payload) : this.orders.create(payload);

    request.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: (order) => {
        this.toast.success(this.isEdit() ? 'Dados de entrada atualizados.' : `OS #${order.number} aberta. Agora adicione o que precisa ser feito.`);
        this.router.navigate(['/service-orders', order.id]);
      },
      error: (error: unknown) => this.handleError(error),
    });
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  private loadOrder(id: number): void {
    this.loading.set(true);
    this.orders
      .get(id)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (order) => {
          if (order.is_final) {
            this.toast.error(`A OS está ${order.status_label.toLowerCase()}. Reabra a OS para editar.`);
            this.router.navigate(['/service-orders', order.id]);
            return;
          }
          this.order.set(order);
          this.form.patchValue({
            mileage: formatMileage(order.mileage),
            expected_at: order.expected_at ?? '',
            complaint: order.complaint ?? '',
            notes: order.notes ?? '',
          });
        },
        error: () => {
          this.toast.error('OS não encontrada.');
          this.router.navigate(['/service-orders']);
        },
      });
  }

  private clearFieldError(field: string): void {
    this.fieldErrors.update(({ [field]: _removed, ...rest }) => rest);
  }

  private handleError(error: unknown): void {
    if (error instanceof HttpErrorResponse && error.status === 422) {
      const { errors } = error.error as ValidationErrorBody;
      const mapped = Object.fromEntries(Object.entries(errors ?? {}).map(([field, messages]) => [field, messages[0]]));
      this.fieldErrors.set(mapped);
      this.formError.set('Revise os campos destacados.');
      return;
    }
    this.formError.set('Não foi possível salvar a OS. Tente novamente.');
  }
}
