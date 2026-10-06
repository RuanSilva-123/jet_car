import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  ElementRef,
  inject,
  input,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormArray, NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { distinctUntilChanged, filter, finalize, map } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { ValidationErrorBody } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { MaskDirective } from '../../../../shared/directives/mask.directive';
import { digits, formatCep, formatDocument, formatPhone } from '../../../../shared/utils/br-format';
import { cepValidator, documentValidator, phoneValidator } from '../../../../shared/utils/br-validators';
import { VehicleEditor } from '../../components/vehicle-editor/vehicle-editor';
import { Customer, PersonType, STATES } from '../../models/customer';
import { CustomerPayload, CustomersService } from '../../services/customers.service';
import { LookupsService } from '../../services/lookups.service';
import { createVehicleForm, toVehiclePayload, VehicleForm } from '../../utils/customer-form';

type CepStatus = 'idle' | 'loading' | 'found' | 'not-found' | 'error';
type FieldName =
  | 'name'
  | 'trade_name'
  | 'document'
  | 'birth_date'
  | 'phone'
  | 'secondary_phone'
  | 'email'
  | 'zip_code'
  | 'state';

const MESSAGES: Record<string, string> = {
  required: 'Campo obrigatório.',
  maxlength: 'Texto muito longo.',
  email: 'Informe um e-mail válido.',
  cpf: 'CPF inválido.',
  cnpj: 'CNPJ inválido.',
  phone: 'Telefone inválido. Use DDD + número.',
  cep: 'CEP deve ter 8 dígitos.',
};

@Component({
  selector: 'app-customer-form',
  imports: [ReactiveFormsModule, RouterLink, Icon, MaskDirective, VehicleEditor, ConfirmDialog],
  templateUrl: './customer-form.html',
  styleUrl: './customer-form.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerForm implements OnInit {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly customers = inject(CustomersService);
  private readonly lookups = inject(LookupsService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  /** Preenchendo com dados salvos: não dispara a busca de CEP (não sobrescreve o endereço). */
  private filling = false;
  private readonly destroyRef = inject(DestroyRef);

  /** Parâmetro :id da rota (ausente no cadastro). */
  readonly id = input<string>();

  protected readonly states = STATES;
  protected readonly isEdit = computed(() => this.id() !== undefined);
  protected readonly canDelete = inject(AuthService).isMaster;

  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly submitted = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly cepStatus = signal<CepStatus>('idle');
  protected readonly loadedCustomer = signal<Customer | null>(null);
  protected readonly confirmDelete = signal(false);
  protected readonly deleting = signal(false);

  /** Tipo atual, lido pelo validador do documento (CPF × CNPJ). */
  private currentPersonType: PersonType = 'individual';

  protected readonly form = this.fb.group({
    person_type: this.fb.control<PersonType>('individual'),
    name: ['', [Validators.required, Validators.maxLength(150)]],
    trade_name: ['', Validators.maxLength(150)],
    document: ['', documentValidator(() => this.currentPersonType)],
    state_registration: ['', Validators.maxLength(20)],
    birth_date: [''],
    phone: ['', [Validators.required, phoneValidator]],
    phone_is_whatsapp: [true],
    secondary_phone: ['', phoneValidator],
    email: ['', [Validators.email, Validators.maxLength(255)]],
    zip_code: ['', cepValidator],
    street: ['', Validators.maxLength(150)],
    number: ['', Validators.maxLength(20)],
    complement: ['', Validators.maxLength(100)],
    neighborhood: ['', Validators.maxLength(100)],
    city: ['', Validators.maxLength(100)],
    state: [''],
    notes: ['', Validators.maxLength(2000)],
    vehicles: this.fb.array<VehicleForm>([]),
  });

  protected readonly personType = toSignal(this.form.controls.person_type.valueChanges, {
    initialValue: this.form.controls.person_type.value,
  });
  protected readonly isCompany = computed(() => this.personType() === 'company');

  protected get vehicles(): FormArray<VehicleForm> {
    return this.form.controls.vehicles;
  }

  /** Hoje, para limitar a data de nascimento. */
  protected readonly today = new Date().toISOString().slice(0, 10);

  ngOnInit(): void {
    // Trocar PF ↔ PJ muda a máscara e a validação do documento
    this.form.controls.person_type.valueChanges.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((type) => {
      this.currentPersonType = type;
      const document = this.form.controls.document;
      document.setValue(formatDocument(document.value));
      document.updateValueAndValidity();
    });

    // CEP completo → busca o endereço
    this.form.controls.zip_code.valueChanges
      .pipe(
        map((value) => digits(value)),
        distinctUntilChanged(),
        filter((cep) => cep.length === 8 && !this.filling),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((cep) => this.lookupCep(cep));

    const id = this.id();
    if (id === undefined) {
      this.addVehicle(false);
      return;
    }

    this.loading.set(true);
    this.customers
      .get(Number(id))
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (customer) => this.fillForm(customer),
        error: () => {
          this.toast.error('Cliente não encontrado.');
          this.router.navigate(['/customers']);
        },
      });
  }

  protected setPersonType(type: PersonType): void {
    this.form.controls.person_type.setValue(type);
  }

  protected addVehicle(focus = true): void {
    this.vehicles.push(createVehicleForm(this.fb));

    if (focus) {
      // Leva a tela até o novo cartão
      setTimeout(() => this.host.nativeElement.querySelector('app-vehicle-editor:last-of-type')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    }
  }

  protected removeVehicle(index: number): void {
    this.vehicles.removeAt(index);
  }

  protected errorFor(field: FieldName): string | null {
    const control = this.form.controls[field];
    if (!control.errors || !(control.touched || this.submitted())) return null;
    if (control.errors['server']) return control.errors['server'];
    return MESSAGES[Object.keys(control.errors)[0]] ?? 'Valor inválido.';
  }

  protected submit(): void {
    this.submitted.set(true);
    this.formError.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.formError.set('Revise os campos destacados.');
      this.scrollToFirstError();
      return;
    }

    this.saving.set(true);
    const raw = this.form.getRawValue();
    const payload: CustomerPayload = { ...raw, vehicles: this.vehicles.controls.map(toVehiclePayload) };

    const request = this.isEdit()
      ? this.customers.update(Number(this.id()), payload)
      : this.customers.create(payload);

    request.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: (customer) => {
        this.toast.success(this.isEdit() ? 'Cliente atualizado.' : `${customer.name} foi cadastrado.`);
        this.router.navigate(['/customers']);
      },
      error: (error: unknown) => this.handleError(error),
    });
  }

  protected deleteCustomer(): void {
    const customer = this.loadedCustomer();
    if (!customer) return;

    this.deleting.set(true);
    this.customers
      .remove(customer.id)
      .pipe(finalize(() => this.deleting.set(false)))
      .subscribe({
        next: () => {
          this.toast.success(`${customer.name} foi excluído.`);
          this.router.navigate(['/customers']);
        },
        error: (error: unknown) => {
          this.confirmDelete.set(false);
          this.toast.error(
            error instanceof HttpErrorResponse && error.status === 403
              ? 'Somente o administrador master pode excluir clientes.'
              : 'Não foi possível excluir o cliente.',
          );
        },
      });
  }

  private lookupCep(cep: string): void {
    this.cepStatus.set('loading');

    this.lookups.address(cep).subscribe({
      next: (address) => {
        this.cepStatus.set('found');
        this.form.patchValue({
          street: address.street || this.form.controls.street.value,
          neighborhood: address.neighborhood || this.form.controls.neighborhood.value,
          city: address.city,
          state: address.state,
          complement: this.form.controls.complement.value || address.complement,
        });
        // Endereço preenchido: o próximo passo natural é o número
        setTimeout(() => this.host.nativeElement.querySelector<HTMLInputElement>('#number')?.focus());
      },
      error: (error: unknown) => {
        this.cepStatus.set(error instanceof HttpErrorResponse && error.status === 404 ? 'not-found' : 'error');
      },
    });
  }

  private fillForm(customer: Customer): void {
    this.loadedCustomer.set(customer);
    this.filling = true;
    this.form.patchValue({
      person_type: customer.person_type,
      name: customer.name,
      trade_name: customer.trade_name ?? '',
      document: formatDocument(customer.document),
      state_registration: customer.state_registration ?? '',
      birth_date: customer.birth_date ?? '',
      phone: formatPhone(customer.phone),
      phone_is_whatsapp: customer.phone_is_whatsapp,
      secondary_phone: formatPhone(customer.secondary_phone),
      email: customer.email ?? '',
      zip_code: formatCep(customer.zip_code),
      street: customer.street ?? '',
      number: customer.number ?? '',
      complement: customer.complement ?? '',
      neighborhood: customer.neighborhood ?? '',
      city: customer.city ?? '',
      state: customer.state ?? '',
      notes: customer.notes ?? '',
    });
    this.filling = false;

    this.vehicles.clear();
    for (const vehicle of customer.vehicles ?? []) {
      this.vehicles.push(createVehicleForm(this.fb, vehicle));
    }
  }

  private handleError(error: unknown): void {
    if (error instanceof HttpErrorResponse && error.status === 422) {
      const { errors } = error.error as ValidationErrorBody;
      for (const [path, messages] of Object.entries(errors ?? {})) {
        // "vehicles.0.plate" funciona direto com form.get()
        const control = this.form.get(path);
        control?.setErrors({ server: messages[0] });
        control?.markAsTouched();
      }
      this.formError.set('Revise os campos destacados.');
      this.scrollToFirstError();
      return;
    }

    this.formError.set('Não foi possível salvar o cliente. Tente novamente em instantes.');
  }

  private scrollToFirstError(): void {
    setTimeout(() =>
      this.host.nativeElement.querySelector('.field--invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
    );
  }
}
