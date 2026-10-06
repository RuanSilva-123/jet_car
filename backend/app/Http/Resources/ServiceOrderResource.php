<?php

namespace App\Http\Resources;

use App\Models\ServiceOrder;
use App\Support\BudgetLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceOrder
 */
class ServiceOrderResource extends JsonResource
{
    /** Relações carregadas no detalhe da OS. */
    public const DETAIL_RELATIONS = ['customer', 'vehicle', 'creator', 'items.doneBy', 'items.mechanic', 'parts', 'payments.receiver', 'inspection', 'events.user'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),
            'mileage' => $this->mileage,
            'complaint' => $this->complaint,
            'notes' => $this->notes,
            'expected_at' => $this->expected_at?->toDateString(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),

            // Orçamento (valores em centavos)
            'labor_total_cents' => $this->labor_total_cents,
            'parts_total_cents' => $this->parts_total_cents,
            'discount_cents' => $this->discount_cents,
            'total_cents' => $this->total_cents,
            'paid_cents' => $this->paid_cents,
            'balance_cents' => $this->balanceCents(),
            'payment_status' => $this->paymentStatus()->value,
            'payment_status_label' => $this->paymentStatus()->label(),
            'budget_sent_at' => $this->budget_sent_at?->toIso8601String(),
            'budget_approved_at' => $this->budget_approved_at?->toIso8601String(),
            'budget_approved_total_cents' => $this->budget_approved_total_cents,
            'budget_changed_after_approval' => $this->budgetChangedAfterApproval(),
            // Detalhe: quantos serviços/peças ainda estão sem valor (orçamento incompleto)
            'unpriced_count' => $this->when($this->relationLoaded('items'), fn () => $this->unpricedCount()),
            // Detalhe: link público do orçamento (cliente aprova/recusa sem login), quando há o que aprovar
            'public_budget_url' => $this->when(
                $this->relationLoaded('items'),
                fn () => ! $this->status->isFinal() && $this->unpricedCount() === 0 && ($this->items->isNotEmpty() || $this->parts()->exists())
                    ? BudgetLink::make($this->resource)['url']
                    : null,
            ),

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->trade_name ?: $this->customer->name,
                'phone' => $this->customer->phone,
                'phone_is_whatsapp' => $this->customer->phone_is_whatsapp,
                'deleted' => $this->customer->trashed(),
            ]),
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'type' => $this->vehicle->type->value,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
                'model_year' => $this->vehicle->model_year,
                'plate' => $this->vehicle->plate,
                'color' => $this->vehicle->color,
                'mileage' => $this->vehicle->mileage,
                'deleted' => $this->vehicle->trashed(),
            ]),

            // Lista: só contadores. Detalhe: itens e linha do tempo completos.
            'items_count' => $this->whenCounted('items'),
            'done_items_count' => $this->when(isset($this->done_items_count), fn () => (int) $this->done_items_count),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'labor_service_id' => $item->labor_service_id,
                'name' => $item->name,
                'notes' => $item->notes,
                'price_cents' => $item->price_cents,
                'is_done' => $item->is_done,
                'done_at' => $item->done_at?->toIso8601String(),
                'done_by' => $item->relationLoaded('doneBy') ? $item->doneBy?->name : null,
                'mechanic_id' => $item->mechanic_id,
                'mechanic' => $item->relationLoaded('mechanic') ? $item->mechanic?->name : null,
            ])),
            'parts' => $this->whenLoaded('parts', fn () => $this->parts->map(fn ($part) => [
                'id' => $part->id,
                'part_id' => $part->part_id,
                'name' => $part->name,
                'part_number' => $part->part_number,
                'quantity' => (float) $part->quantity,
                'unit_price_cents' => $part->unit_price_cents,
                'total_cents' => $part->totalCents(),
            ])),
            // Detalhe: situação da vistoria de entrada (o conteúdo vem de /inspection)
            'inspection' => $this->whenLoaded('inspection', fn () => [
                'exists' => $this->inspection !== null,
                'updated_at' => $this->inspection?->updated_at?->toIso8601String(),
            ]),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->method->value,
                'method_label' => $payment->method->label(),
                'amount_cents' => $payment->amount_cents,
                'installments' => $payment->installments,
                'paid_at' => $payment->paid_at->toDateString(),
                'notes' => $payment->notes,
                'received_by' => $payment->relationLoaded('receiver') ? $payment->receiver?->name : null,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'description' => $event->description,
                'from_status' => $event->from_status?->value,
                'to_status' => $event->to_status?->value,
                'user' => $event->user?->name,
                'created_at' => $event->created_at?->toIso8601String(),
            ])),
        ];
    }
}
