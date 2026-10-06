import { IconName } from '../../../shared/components/icon/icon';
import { VehicleType } from '../../customers/models/customer';

export type ServiceOrderStatus =
  | 'open'
  | 'in_progress'
  | 'waiting_approval'
  | 'waiting_parts'
  | 'completed'
  | 'delivered'
  | 'canceled';

export interface ServiceOrderItem {
  id: number;
  labor_service_id: number | null;
  name: string;
  notes: string | null;
  /** null = "a definir" (ainda sem orçamento). */
  price_cents: number | null;
  is_done: boolean;
  done_at: string | null;
  done_by: string | null;
  /** Mecânico responsável. */
  mechanic_id: number | null;
  mechanic: string | null;
}

export interface ServiceOrderPart {
  id: number;
  /** Peça do estoque (null = peça avulsa digitada na OS). */
  part_id: number | null;
  name: string;
  part_number: string | null;
  quantity: number;
  /** null = "a definir" (ainda sem orçamento). */
  unit_price_cents: number | null;
  total_cents: number | null;
}

export interface ServiceOrderEvent {
  id: number;
  type:
    | 'created'
    | 'status_changed'
    | 'item_added'
    | 'item_removed'
    | 'item_done'
    | 'item_undone'
    | 'part_added'
    | 'part_removed'
    | 'updated'
    | 'note'
    | 'budget_updated'
    | 'budget_sent'
    | 'budget_approved'
    | 'budget_rejected'
    | 'item_assigned'
    | 'payment_added'
    | 'payment_removed'
    | 'inspection'
    | 'inspection_signed';
  description: string;
  from_status: ServiceOrderStatus | null;
  to_status: ServiceOrderStatus | null;
  user: string | null;
  created_at: string;
}

export type PaymentMethod = 'pix' | 'cash' | 'credit_card' | 'debit_card' | 'bank_transfer' | 'bank_slip' | 'other';
export type PaymentStatus = 'none' | 'pending' | 'partial' | 'paid';

export const PAYMENT_METHODS: { value: PaymentMethod; label: string }[] = [
  { value: 'pix', label: 'Pix' },
  { value: 'cash', label: 'Dinheiro' },
  { value: 'credit_card', label: 'Cartão de crédito' },
  { value: 'debit_card', label: 'Cartão de débito' },
  { value: 'bank_transfer', label: 'Transferência' },
  { value: 'bank_slip', label: 'Boleto' },
  { value: 'other', label: 'Outro' },
];

export interface ServiceOrderPayment {
  id: number;
  method: PaymentMethod;
  method_label: string;
  amount_cents: number;
  installments: number;
  paid_at: string;
  notes: string | null;
  received_by: string | null;
}

export interface ServiceOrderCustomer {
  id: number;
  name: string;
  phone: string;
  phone_is_whatsapp: boolean;
  deleted: boolean;
}

export interface ServiceOrderVehicle {
  id: number;
  type: VehicleType;
  brand: string;
  model: string;
  model_year: number | null;
  plate: string | null;
  color: string | null;
  mileage: number | null;
  deleted: boolean;
}

export interface ServiceOrder {
  id: number;
  number: string;
  status: ServiceOrderStatus;
  status_label: string;
  is_final: boolean;
  mileage: number | null;
  complaint: string | null;
  notes: string | null;
  expected_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  delivered_at: string | null;
  canceled_at: string | null;
  created_at: string;
  updated_at: string;
  created_by?: string | null;
  labor_total_cents: number;
  parts_total_cents: number;
  discount_cents: number;
  total_cents: number;
  budget_sent_at: string | null;
  budget_approved_at: string | null;
  budget_approved_total_cents: number | null;
  budget_changed_after_approval: boolean;
  paid_cents: number;
  /** Quanto falta receber (negativo = pagou a mais). */
  balance_cents: number;
  payment_status: PaymentStatus;
  payment_status_label: string;
  /** Detalhe: link público do orçamento (cliente aprova sem login). */
  public_budget_url?: string | null;
  payments?: ServiceOrderPayment[];
  inspection?: { exists: boolean; updated_at: string | null };
  /** Detalhe: serviços/peças ainda sem valor. */
  unpriced_count?: number;
  customer?: ServiceOrderCustomer;
  vehicle?: ServiceOrderVehicle;
  items_count?: number;
  done_items_count?: number;
  items?: ServiceOrderItem[];
  parts?: ServiceOrderPart[];
  events?: ServiceOrderEvent[];
}

/** Entrada do veículo (abertura e edição dos dados de entrada). */
export interface ServiceOrderEntryPayload {
  customer_id?: number;
  vehicle_id?: number;
  mileage: string;
  complaint: string;
  notes: string;
  expected_at: string;
}

/** Montagem do orçamento: serviços, peças e desconto (valor null = "a definir"). */
export interface ServiceOrderBudgetPayload {
  items: { id?: number | null; labor_service_id?: number | null; notes: string; price_cents: number | null }[];
  parts: { id?: number | null; part_id?: number | null; name: string; part_number: string; quantity: number; unit_price_cents: number | null }[];
  discount_cents: number;
}

export type ServiceOrderPayload = ServiceOrderEntryPayload | ServiceOrderBudgetPayload;

export interface StatusMeta {
  label: string;
  tone: 'info' | 'brand' | 'warning' | 'success' | 'ink' | 'neutral';
  icon: IconName;
}

/** Rótulo, cor e ícone de cada status (mesmos rótulos do backend). */
export const STATUS_META: Record<ServiceOrderStatus, StatusMeta> = {
  open: { label: 'Aberta', tone: 'info', icon: 'clipboard' },
  in_progress: { label: 'Em andamento', tone: 'brand', icon: 'wrench' },
  waiting_approval: { label: 'Aguardando aprovação', tone: 'warning', icon: 'clock' },
  waiting_parts: { label: 'Aguardando peças', tone: 'warning', icon: 'package' },
  completed: { label: 'Concluída', tone: 'success', icon: 'check' },
  delivered: { label: 'Entregue', tone: 'ink', icon: 'car' },
  canceled: { label: 'Cancelada', tone: 'neutral', icon: 'close' },
};

export const STATUS_ORDER: ServiceOrderStatus[] = [
  'open',
  'in_progress',
  'waiting_approval',
  'waiting_parts',
  'completed',
  'delivered',
  'canceled',
];

export const FINAL_STATUSES: ServiceOrderStatus[] = ['delivered', 'canceled'];

export type NextStep =
  | { kind: 'status'; status: ServiceOrderStatus; label: string; icon: IconName }
  | { kind: 'diagnose' | 'edit-budget' | 'send-budget' | 'approve-budget'; label: string; icon: IconName };

type FlowFields = Pick<ServiceOrder, 'status' | 'budget_approved_at' | 'items' | 'parts' | 'unpriced_count'>;

/** Linhas do orçamento (serviços + peças). */
export function lineCount(order: Pick<ServiceOrder, 'items' | 'parts'>): number {
  return (order.items?.length ?? 0) + (order.parts?.length ?? 0);
}

/**
 * Próximo passo natural da OS (botão principal):
 * entrada → diagnóstico (o que fazer) → montar orçamento (valores) → enviar → aprovação → executar → concluir → entregar.
 */
export function nextStep(order: FlowFields): NextStep {
  const approved = !!order.budget_approved_at;

  switch (order.status) {
    case 'open':
      if (lineCount(order) === 0) return { kind: 'diagnose', label: 'Adicionar serviços', icon: 'plus' };
      return order.unpriced_count
        ? { kind: 'edit-budget', label: 'Montar orçamento', icon: 'wallet' }
        : { kind: 'send-budget', label: 'Enviar orçamento', icon: 'arrow-right' };
    case 'waiting_approval':
      return { kind: 'approve-budget', label: 'Registrar aprovação', icon: 'check' };
    case 'in_progress':
      return { kind: 'status', status: 'completed', label: 'Concluir serviço', icon: 'check' };
    case 'waiting_parts':
      return { kind: 'status', status: 'in_progress', label: 'Retomar serviço', icon: 'play' };
    case 'completed':
      return { kind: 'status', status: 'delivered', label: 'Registrar entrega', icon: 'car' };
    case 'delivered':
    case 'canceled':
      return { kind: 'status', status: approved ? 'in_progress' : 'open', label: 'Reabrir OS', icon: 'play' };
  }
}

/** Status que só podem ser usados com o orçamento aprovado (mesma regra da API). */
export const REQUIRES_APPROVED_BUDGET: ServiceOrderStatus[] = ['in_progress', 'waiting_parts', 'completed', 'delivered'];

/** Etapas mostradas no detalhe da OS. */
export const FLOW_STEPS = ['Entrada', 'Diagnóstico', 'Orçamento', 'Aprovação', 'Execução', 'Entrega'] as const;

/** Etapa atual (índice em FLOW_STEPS); FLOW_STEPS.length = tudo concluído; null = cancelada. */
export function flowStep(order: FlowFields): number | null {
  switch (order.status) {
    case 'open':
      return lineCount(order) === 0 ? 1 : 2;
    case 'waiting_approval':
      return 3;
    case 'in_progress':
    case 'waiting_parts':
      return 4;
    case 'completed':
      return 5;
    case 'delivered':
      return FLOW_STEPS.length;
    case 'canceled':
      return null;
  }
}
