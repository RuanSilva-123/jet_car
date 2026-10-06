import { FormControl, FormGroup, NonNullableFormBuilder, Validators } from '@angular/forms';

import { formatMileage, formatPlate } from '../../../shared/utils/br-format';
import { plateValidator, renavamValidator, vinValidator } from '../../../shared/utils/br-validators';
import { CatalogItem, FuelType, Vehicle, VehicleType } from '../models/customer';
import { VehiclePayload } from '../services/customers.service';

export type VehicleForm = FormGroup<{
  id: FormControl<number | null>;
  type: FormControl<VehicleType>;
  brand: FormControl<string>;
  model: FormControl<string>;
  model_year: FormControl<number | null>;
  manufacture_year: FormControl<number | null>;
  fuel: FormControl<FuelType | null>;
  plate: FormControl<string>;
  color: FormControl<string>;
  mileage: FormControl<string>;
  vin: FormControl<string>;
  renavam: FormControl<string>;
  fipe_brand_code: FormControl<string | null>;
  fipe_model_code: FormControl<string | null>;
  fipe_year_code: FormControl<string | null>;
  notes: FormControl<string>;
}>;

const NEXT_YEAR = new Date().getFullYear() + 1;

export function createVehicleForm(fb: NonNullableFormBuilder, vehicle?: Vehicle): VehicleForm {
  return fb.group({
    id: fb.control<number | null>(vehicle?.id ?? null),
    type: fb.control<VehicleType>(vehicle?.type ?? 'car'),
    brand: fb.control(vehicle?.brand ?? '', [Validators.required, Validators.maxLength(80)]),
    model: fb.control(vehicle?.model ?? '', [Validators.required, Validators.maxLength(150)]),
    model_year: fb.control<number | null>(vehicle?.model_year ?? null, [Validators.min(1900), Validators.max(NEXT_YEAR)]),
    manufacture_year: fb.control<number | null>(vehicle?.manufacture_year ?? null, [
      Validators.min(1900),
      Validators.max(NEXT_YEAR),
    ]),
    fuel: fb.control<FuelType | null>(vehicle?.fuel ?? null),
    plate: fb.control(formatPlate(vehicle?.plate), plateValidator),
    color: fb.control(vehicle?.color ?? '', Validators.maxLength(40)),
    mileage: fb.control(formatMileage(vehicle?.mileage)),
    vin: fb.control(vehicle?.vin ?? '', vinValidator),
    renavam: fb.control(vehicle?.renavam ?? '', renavamValidator),
    fipe_brand_code: fb.control<string | null>(vehicle?.fipe_brand_code ?? null),
    fipe_model_code: fb.control<string | null>(vehicle?.fipe_model_code ?? null),
    fipe_year_code: fb.control<string | null>(vehicle?.fipe_year_code ?? null),
    notes: fb.control(vehicle?.notes ?? '', Validators.maxLength(1000)),
  });
}

export function toVehiclePayload(form: VehicleForm): VehiclePayload {
  return form.getRawValue();
}

/** Ano 32000 é como a FIPE representa veículo "Zero KM". */
const FIPE_ZERO_KM = 32000;

const FUEL_BY_FIPE_NAME: [RegExp, FuelType][] = [
  [/flex/i, 'flex'],
  [/gasolina/i, 'gasoline'],
  [/(álcool|alcool|etanol)/i, 'ethanol'],
  [/diesel/i, 'diesel'],
  [/(elétrico|eletrico)/i, 'electric'],
  [/(híbrido|hibrido)/i, 'hybrid'],
  [/(gnv|gás|gas natural)/i, 'cng'],
];

/** "2013 Flex" (código "2013-5") → { year: 2013, fuel: 'flex' } */
export function parseFipeYear(item: CatalogItem): { year: number; fuel: FuelType | null } {
  const rawYear = Number.parseInt(item.code, 10);
  const year = rawYear === FIPE_ZERO_KM ? new Date().getFullYear() : rawYear;
  const fuel = FUEL_BY_FIPE_NAME.find(([pattern]) => pattern.test(item.name))?.[1] ?? null;
  return { year, fuel };
}

/** Nome amigável para os anos da FIPE: "32000 Gasolina" → "Zero KM · Gasolina". */
export function displayFipeYear(item: CatalogItem): CatalogItem {
  const [year, ...rest] = item.name.split(' ');
  const label = Number(year) === FIPE_ZERO_KM ? 'Zero KM' : year;
  return { code: item.code, name: rest.length ? `${label} · ${rest.join(' ')}` : label };
}

const normalizeText = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * Escolhe, entre os anos da marca na FIPE, o que corresponde ao veículo da placa.
 * Prioriza o combustível da correspondência FIPE do provedor (é nele que está o código do modelo),
 * depois o combustível do documento do veículo e, por fim, o primeiro do mesmo ano.
 */
export function pickFipeYear(
  years: CatalogItem[],
  modelYear: number | null,
  fipeFuelLabel: string | null,
  fuel: FuelType | null,
): CatalogItem | null {
  const sameYear = years.filter((item) => Number.parseInt(item.code, 10) === modelYear);
  if (sameYear.length === 0) return null;

  if (fipeFuelLabel) {
    const label = normalizeText(fipeFuelLabel);
    const match = sameYear.find((item) => normalizeText(item.name).includes(label));
    if (match) return match;
  }

  if (fuel) {
    const match = sameYear.find((item) => parseFipeYear(item).fuel === fuel);
    if (match) return match;
  }

  return sameYear[0];
}

/**
 * Ordena os anos de uma marca: Zero KM primeiro, depois do mais novo ao mais antigo
 * e, dentro do mesmo ano, por combustível (a FIPE devolve fora de ordem).
 */
export function sortFipeYears(items: CatalogItem[]): CatalogItem[] {
  return [...items].sort((a, b) => {
    const yearDiff = Number.parseInt(b.code, 10) - Number.parseInt(a.code, 10);
    return yearDiff !== 0 ? yearDiff : a.name.localeCompare(b.name, 'pt-BR');
  });
}
