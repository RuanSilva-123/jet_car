export type PersonType = 'individual' | 'company';
export type VehicleType = 'car' | 'motorcycle' | 'truck';
export type FuelType = 'flex' | 'gasoline' | 'ethanol' | 'diesel' | 'electric' | 'hybrid' | 'cng';

export interface Vehicle {
  id: number;
  type: VehicleType;
  type_label: string;
  brand: string;
  model: string;
  model_year: number | null;
  manufacture_year: number | null;
  fuel: FuelType | null;
  fuel_label: string | null;
  plate: string | null;
  color: string | null;
  mileage: number | null;
  vin: string | null;
  renavam: string | null;
  fipe_brand_code: string | null;
  fipe_model_code: string | null;
  fipe_year_code: string | null;
  notes: string | null;
}

export interface Customer {
  id: number;
  person_type: PersonType;
  person_type_label: string;
  name: string;
  trade_name: string | null;
  document: string | null;
  state_registration: string | null;
  birth_date: string | null;
  phone: string;
  phone_is_whatsapp: boolean;
  secondary_phone: string | null;
  email: string | null;
  zip_code: string | null;
  street: string | null;
  number: string | null;
  complement: string | null;
  neighborhood: string | null;
  city: string | null;
  state: string | null;
  notes: string | null;
  vehicles_count?: number;
  vehicles?: Vehicle[];
  created_at: string | null;
  updated_at: string | null;
}

/** Item do catálogo FIPE (marca, modelo ou ano). */
export interface CatalogItem {
  code: string;
  name: string;
}

/** Dados do veículo obtidos pela placa (GET /plate-lookup/{placa}). */
export interface PlateData {
  plate: string;
  type: VehicleType | null;
  brand: string | null;
  model: string | null;
  model_year: number | null;
  manufacture_year: number | null;
  fuel: FuelType | null;
  color: string | null;
  city: string | null;
  state: string | null;
  /** Códigos da Tabela FIPE quando o provedor encontrou a correspondência. */
  fipe: { brand_code: string; model_code: string; model_year: number | null; fuel: string | null } | null;
}

export interface PlateLookupStatus {
  enabled: boolean;
  provider: string | null;
}

export interface Address {
  zip_code: string;
  street: string;
  complement: string;
  neighborhood: string;
  city: string;
  state: string;
}

export const VEHICLE_TYPES: { value: VehicleType; label: string }[] = [
  { value: 'car', label: 'Carro' },
  { value: 'motorcycle', label: 'Moto' },
  { value: 'truck', label: 'Caminhão' },
];

export const FUEL_TYPES: { value: FuelType; label: string }[] = [
  { value: 'flex', label: 'Flex' },
  { value: 'gasoline', label: 'Gasolina' },
  { value: 'ethanol', label: 'Etanol' },
  { value: 'diesel', label: 'Diesel' },
  { value: 'electric', label: 'Elétrico' },
  { value: 'hybrid', label: 'Híbrido' },
  { value: 'cng', label: 'GNV' },
];

export const STATES = [
  'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
  'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
];

export const COMMON_COLORS = [
  'Branco', 'Preto', 'Prata', 'Cinza', 'Vermelho', 'Azul', 'Verde', 'Amarelo',
  'Marrom', 'Bege', 'Laranja', 'Vinho', 'Dourado',
];
