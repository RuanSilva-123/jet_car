export interface Part {
  id: number;
  name: string;
  part_number: string | null;
  brand: string | null;
  unit: string;
  cost_cents: number | null;
  price_cents: number | null;
  /** Margem sobre o preço de venda (%). */
  margin_percent: number | null;
  stock_quantity: number;
  min_stock: number;
  /** No mínimo definido ou negativo. */
  is_low_stock: boolean;
  is_active: boolean;
  notes: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface PartPayload {
  name: string;
  part_number: string;
  brand: string;
  unit: string;
  cost_cents: number | null;
  price_cents: number | null;
  min_stock: number;
  is_active: boolean;
  notes: string;
  /** Só no cadastro: lança a primeira entrada. */
  initial_stock?: number;
}

export type StockMovementType = 'entry' | 'adjustment' | 'order_out' | 'order_return';

export interface StockMovement {
  id: number;
  type: StockMovementType;
  type_label: string;
  quantity: number;
  balance_after: number;
  unit_cost_cents: number | null;
  notes: string | null;
  user: string | null;
  service_order: { id: number; number: string } | null;
  created_at: string;
}

/** Unidades aceitas pela API (SavePartRequest::UNITS). */
export const PART_UNITS = ['un', 'par', 'jogo', 'kit', 'L', 'ml', 'kg', 'g', 'm', 'cm'];

/** Quantidade em estoque com sinal (o estoque pode ficar negativo): -1.5 → "−1,5". */
export function formatStock(value: number): string {
  const text = String(Math.round(Math.abs(value) * 100) / 100).replace('.', ',');
  return value < 0 ? `−${text}` : text;
}
