export type ServiceCategory =
  | 'maintenance'
  | 'engine'
  | 'suspension'
  | 'brakes'
  | 'transmission'
  | 'steering'
  | 'cooling'
  | 'electrical'
  | 'air_conditioning'
  | 'exhaust'
  | 'tires'
  | 'other';

/** Serviço (mão de obra) que a oficina realiza. Sem valores por enquanto. */
export interface LaborService {
  id: number;
  name: string;
  category: ServiceCategory;
  category_label: string;
  description: string | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface LaborServicePayload {
  name: string;
  category: ServiceCategory;
  description: string;
  is_active: boolean;
}

/** Mesma ordem e rótulos do backend (App\Enums\ServiceCategory). */
export const SERVICE_CATEGORIES: { value: ServiceCategory; label: string }[] = [
  { value: 'maintenance', label: 'Revisão e manutenção' },
  { value: 'engine', label: 'Motor' },
  { value: 'suspension', label: 'Suspensão' },
  { value: 'brakes', label: 'Freios' },
  { value: 'transmission', label: 'Embreagem e transmissão' },
  { value: 'steering', label: 'Direção' },
  { value: 'cooling', label: 'Arrefecimento' },
  { value: 'electrical', label: 'Elétrica' },
  { value: 'air_conditioning', label: 'Ar-condicionado' },
  { value: 'exhaust', label: 'Escapamento' },
  { value: 'tires', label: 'Pneus e rodas' },
  { value: 'other', label: 'Outros' },
];
