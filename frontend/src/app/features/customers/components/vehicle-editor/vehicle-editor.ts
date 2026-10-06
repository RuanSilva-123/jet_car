import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  OnInit,
  output,
  signal,
  WritableSignal,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { distinctUntilChanged, filter, finalize, map, Observable, startWith, switchMap } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';

import { Combobox } from '../../../../shared/components/combobox/combobox';
import { Icon, IconName } from '../../../../shared/components/icon/icon';
import { MaskDirective } from '../../../../shared/directives/mask.directive';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { alphanumeric } from '../../../../shared/utils/br-format';
import { CatalogItem, COMMON_COLORS, FUEL_TYPES, PlateData, VEHICLE_TYPES, VehicleType } from '../../models/customer';
import { LookupsService } from '../../services/lookups.service';
import { displayFipeYear, parseFipeYear, pickFipeYear, sortFipeYears, VehicleForm } from '../../utils/customer-form';

type PlateStatus = 'idle' | 'loading' | 'found' | 'partial' | 'not-found' | 'error';

const PLATE_REGEX = /^[A-Z]{3}\d[A-Z0-9]\d{2}$/;

type CatalogLevel = 'brands' | 'models' | 'years';

const TYPE_ICONS: Record<VehicleType, IconName> = { car: 'car', motorcycle: 'bike', truck: 'truck' };

const ERRORS: Record<string, Record<string, string>> = {
  brand: { required: 'Informe a marca.' },
  model: { required: 'Informe o modelo.' },
  model_year: { min: 'Ano inválido.', max: 'Ano inválido.' },
  manufacture_year: { min: 'Ano inválido.', max: 'Ano inválido.' },
  plate: { plate: 'Placa inválida. Use ABC1234 ou ABC1D23.' },
  vin: { vin: 'Chassi inválido (17 caracteres, sem I, O e Q).' },
  renavam: { renavam: 'Renavam deve ter de 9 a 11 dígitos.' },
};

/**
 * Cartão de um veículo do cliente.
 * 1. Placa: se a consulta estiver configurada, preenche marca, ano, modelo, combustível e cor.
 * 2. Marca → ano → modelo pela Tabela FIPE (com busca), ou preenchimento manual.
 */
@Component({
  selector: 'app-vehicle-editor',
  imports: [ReactiveFormsModule, RouterLink, Combobox, Icon, MaskDirective, BrFormatPipe],
  templateUrl: './vehicle-editor.html',
  styleUrl: './vehicle-editor.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VehicleEditor implements OnInit {
  private readonly lookups = inject(LookupsService);
  private readonly destroyRef = inject(DestroyRef);

  readonly form = input.required<VehicleForm>();
  readonly index = input.required<number>();
  /** O formulário pai já tentou salvar: mostra erros mesmo em campos não tocados. */
  readonly submitted = input(false);
  /** Cliente já salvo: habilita "Nova OS" e "Histórico" nos veículos salvos. */
  readonly customerId = input<number | null>(null);

  readonly remove = output<void>();

  protected readonly vehicleTypes = VEHICLE_TYPES;
  protected readonly fuelTypes = FUEL_TYPES;
  protected readonly colors = COMMON_COLORS;

  protected readonly manual = signal(false);
  protected readonly catalogError = signal<string | null>(null);
  protected readonly brands = signal<CatalogItem[]>([]);
  protected readonly models = signal<CatalogItem[]>([]);
  protected readonly years = signal<CatalogItem[]>([]);
  protected readonly loading = signal<Record<CatalogLevel, boolean>>({ brands: false, models: false, years: false });

  /** Espelho reativo dos valores do formulário (para título, ícone e listas dependentes). */
  protected readonly value = signal<ReturnType<VehicleForm['getRawValue']> | null>(null);

  protected readonly yearOptions = computed(() => sortFipeYears(this.years()).map(displayFipeYear));

  protected readonly typeIcon = computed(() => TYPE_ICONS[this.value()?.type ?? 'car']);

  protected readonly title = computed(() => {
    const vehicle = this.value();
    const name = [vehicle?.brand, vehicle?.model].filter(Boolean).join(' ');
    return name || `Veículo ${this.index() + 1}`;
  });

  /** Ano de fabricação: o próprio ano do modelo ou 1 antes (regra da API). */
  protected readonly manufactureYears = computed(() => {
    const modelYear = this.value()?.model_year;
    return modelYear ? [modelYear, modelYear - 1] : [];
  });

  protected readonly listId = computed(() => `vehicle-${this.index()}`);

  // Consulta pela placa
  protected readonly plateLookupEnabled = toSignal(this.lookups.plateLookupStatus().pipe(map((status) => status.enabled)), {
    initialValue: false,
  });
  protected readonly isMaster = inject(AuthService).isMaster;
  protected readonly plateStatus = signal<PlateStatus>('idle');
  protected readonly plateMessage = signal<string | null>(null);
  private lastLookedUpPlate: string | null = null;

  ngOnInit(): void {
    const form = this.form();

    form.valueChanges
      .pipe(startWith(null), takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.value.set(form.getRawValue()));

    // Placa completa num veículo ainda vazio → consulta automática (economiza cliques)
    form.controls.plate.valueChanges
      .pipe(
        map((plate) => alphanumeric(plate)),
        distinctUntilChanged(),
        filter((plate) => PLATE_REGEX.test(plate) && this.plateLookupEnabled() && !form.controls.brand.value),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(() => this.lookupPlate());

    const { brand, fipe_brand_code, fipe_year_code } = form.getRawValue();

    if (brand && !fipe_brand_code) {
      // Veículo cadastrado manualmente
      this.manual.set(true);
      return;
    }

    // Edição: recarrega as listas para os campos mostrarem o que foi escolhido
    this.loadBrands();
    if (fipe_brand_code) this.loadYears();
    if (fipe_year_code) this.loadModels();
  }

  protected errorFor(field: keyof typeof ERRORS): string | null {
    const control = this.form().get(field);
    if (!control?.errors || !(control.touched || this.submitted())) return null;
    if (control.errors['server']) return control.errors['server'];
    const key = Object.keys(control.errors)[0];
    return ERRORS[field]?.[key] ?? 'Valor inválido.';
  }

  /** Busca os dados do veículo pela placa e preenche o cartão. */
  protected lookupPlate(): void {
    const plateControl = this.form().controls.plate;
    const plate = alphanumeric(plateControl.value);

    if (!PLATE_REGEX.test(plate)) {
      plateControl.markAsTouched();
      return;
    }
    if (this.plateStatus() === 'loading') return;

    this.lastLookedUpPlate = plate;
    this.plateStatus.set('loading');
    this.plateMessage.set(null);

    this.lookups
      .plate(plate)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (data) => {
          // A placa mudou enquanto a consulta acontecia: ignora a resposta antiga
          if (alphanumeric(this.form().controls.plate.value) !== this.lastLookedUpPlate) {
            this.plateStatus.set('idle');
            return;
          }
          this.applyPlateData(data);
        },
        error: (error: unknown) => {
          const status = error instanceof HttpErrorResponse ? error.status : 0;
          this.plateStatus.set(status === 404 ? 'not-found' : 'error');
          this.plateMessage.set(
            error instanceof HttpErrorResponse && error.error?.message
              ? error.error.message
              : 'Não foi possível consultar a placa. Preencha os dados manualmente.',
          );
        },
      });
  }

  private applyPlateData(data: PlateData): void {
    const form = this.form();
    if (data.type && data.type !== form.controls.type.value) {
      form.patchValue({ type: data.type });
    }
    if (data.color && !form.controls.color.value) {
      form.patchValue({ color: data.color });
    }

    if (data.fipe) {
      this.fillFromFipe(data);
    } else {
      this.fillManually(data);
    }
  }

  /** Seleciona marca, ano e modelo nas listas da FIPE a partir dos códigos devolvidos pela placa. */
  private fillFromFipe(data: PlateData): void {
    const fipe = data.fipe!;
    const { type } = this.form().getRawValue();

    this.manual.set(false);
    this.catalogError.set(null);
    this.loadBrands();

    this.lookups
      .years(type, fipe.brand_code)
      .pipe(
        switchMap((years) => {
          this.years.set(years);
          const year = pickFipeYear(years, fipe.model_year ?? data.model_year, fipe.fuel, data.fuel);
          if (!year) throw new Error('Ano não encontrado na FIPE');
          return this.lookups.models(type, fipe.brand_code, year.code).pipe(map((models) => ({ year, models })));
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: ({ year, models }) => {
          const model = models.find((item) => item.code === fipe.model_code);
          if (!model) {
            this.fillManually(data);
            return;
          }

          const parsed = parseFipeYear(year);
          this.models.set(models);
          this.form().patchValue({
            brand: data.brand ?? '',
            fipe_brand_code: fipe.brand_code,
            fipe_year_code: year.code,
            model_year: parsed.year,
            fuel: data.fuel ?? parsed.fuel,
            model: model.name,
            fipe_model_code: model.code,
            manufacture_year: this.validManufactureYear(data.manufacture_year, parsed.year),
          });
          this.markIdentityTouched();
          this.plateStatus.set('found');
          this.plateMessage.set('Dados preenchidos pela placa. Confira antes de salvar.');
        },
        // Código FIPE do provedor não bateu com o catálogo: usa os textos
        error: () => this.fillManually(data),
      });
  }

  /** Sem correspondência na FIPE: preenche como texto (modo manual). */
  private fillManually(data: PlateData): void {
    this.manual.set(true);
    this.form().patchValue({
      brand: data.brand ?? '',
      model: data.model ?? '',
      model_year: data.model_year,
      manufacture_year: this.validManufactureYear(data.manufacture_year, data.model_year),
      fuel: data.fuel ?? this.form().controls.fuel.value,
      fipe_brand_code: null,
      fipe_year_code: null,
      fipe_model_code: null,
    });
    this.markIdentityTouched();
    this.plateStatus.set('found');
    this.plateMessage.set('Dados preenchidos pela placa. Confira antes de salvar.');
  }

  /** Ano de fabricação precisa ser o do modelo ou 1 antes (regra da API). */
  private validManufactureYear(manufactureYear: number | null, modelYear: number | null): number | null {
    if (!modelYear) return manufactureYear;
    return manufactureYear === modelYear || manufactureYear === modelYear - 1 ? manufactureYear : modelYear;
  }

  private markIdentityTouched(): void {
    this.form().controls.brand.markAsTouched();
    this.form().controls.model.markAsTouched();
  }

  protected setType(type: VehicleType): void {
    if (this.form().controls.type.value === type) return;

    this.form().patchValue({ type });
    this.clearVehicleIdentity();
    if (!this.manual()) this.loadBrands();
  }

  /** 1º passo: marca → carrega os anos em que ela tem veículos. */
  protected selectBrand(brand: CatalogItem): void {
    this.form().patchValue({
      brand: brand.name,
      fipe_brand_code: brand.code,
      model_year: null,
      fipe_year_code: null,
      model: '',
      fipe_model_code: null,
    });
    this.form().controls.brand.markAsTouched();
    this.resetModelField();
    this.years.set([]);
    this.models.set([]);
    this.loadYears();
  }

  /** 2º passo: ano/combustível → define ano, combustível e carrega só os modelos daquele ano. */
  protected selectYear(item: CatalogItem): void {
    const { year, fuel } = parseFipeYear(item);
    const current = this.form().getRawValue();
    const manufactureStillValid = current.manufacture_year === year || current.manufacture_year === year - 1;

    this.form().patchValue({
      fipe_year_code: item.code,
      model_year: year,
      fuel: fuel ?? current.fuel,
      manufacture_year: manufactureStillValid ? current.manufacture_year : year,
      model: '',
      fipe_model_code: null,
    });
    this.resetModelField();
    this.models.set([]);
    this.loadModels();
  }

  /** Modelo limpo automaticamente (troca de marca/ano) não deve aparecer já com erro. */
  private resetModelField(): void {
    this.form().controls.model.markAsUntouched();
  }

  /** 3º passo: modelo da marca naquele ano. */
  protected selectModel(model: CatalogItem): void {
    this.form().patchValue({ model: model.name, fipe_model_code: model.code });
    this.form().controls.model.markAsTouched();
  }

  /** Alterna entre catálogo FIPE e digitação livre. */
  protected toggleManual(): void {
    if (this.manual()) {
      this.manual.set(false);
      this.catalogError.set(null);
      this.clearVehicleIdentity();
      this.loadBrands();
      return;
    }

    // Mantém o que já foi escolhido como texto editável, sem vínculo com a FIPE
    this.manual.set(true);
    this.form().patchValue({ fipe_brand_code: null, fipe_model_code: null, fipe_year_code: null });
  }

  private clearVehicleIdentity(): void {
    this.form().patchValue({
      brand: '',
      model: '',
      model_year: null,
      manufacture_year: null,
      fuel: null,
      fipe_brand_code: null,
      fipe_model_code: null,
      fipe_year_code: null,
    });
    this.brands.set([]);
    this.models.set([]);
    this.years.set([]);
  }

  private loadBrands(): void {
    const { type } = this.form().getRawValue();
    this.load('brands', this.lookups.brands(type), this.brands);
  }

  private loadYears(): void {
    const { type, fipe_brand_code } = this.form().getRawValue();
    if (!fipe_brand_code) return;
    this.load('years', this.lookups.years(type, fipe_brand_code), this.years);
  }

  private loadModels(): void {
    const { type, fipe_brand_code, fipe_year_code } = this.form().getRawValue();
    if (!fipe_brand_code || !fipe_year_code) return;
    this.load('models', this.lookups.models(type, fipe_brand_code, fipe_year_code), this.models);
  }

  private load(level: CatalogLevel, request: Observable<CatalogItem[]>, target: WritableSignal<CatalogItem[]>): void {
    this.loading.update((state) => ({ ...state, [level]: true }));

    request
      .pipe(
        finalize(() => this.loading.update((state) => ({ ...state, [level]: false }))),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (items) => target.set(items),
        error: (error: unknown) => {
          // FIPE fora do ar / cota esgotada: segue no modo manual sem perder o que foi digitado
          const message =
            error instanceof HttpErrorResponse && error.error?.message
              ? error.error.message
              : 'Não foi possível consultar a Tabela FIPE. Preencha os dados manualmente.';
          this.catalogError.set(message);
          this.manual.set(true);
          this.form().patchValue({ fipe_brand_code: null, fipe_model_code: null, fipe_year_code: null });
        },
      });
  }
}
