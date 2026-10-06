<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Services\ServiceOrders\ServiceOrderManager;
use App\Support\BudgetLink;
use App\Support\Money;
use App\Support\Pix\PixCharge;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orçamento aberto pelo cliente no link do WhatsApp (sem login). Mostra só o necessário
 * para decidir: oficina, veículo, itens e totais. Nada de telefone, documento ou endereço.
 */
class PublicBudgetController extends Controller
{
    public function __construct(private readonly ServiceOrderManager $orders) {}

    public function show(string $token): JsonResponse
    {
        return $this->present(BudgetLink::resolve($token));
    }

    public function approve(Request $request, string $token): JsonResponse
    {
        $order = BudgetLink::resolve($token);
        $data = $request->validate([
            // Valor que o cliente viu: se a oficina mudou o orçamento nesse meio-tempo, ele precisa rever
            'total_cents' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $this->ensureCanDecide($order, (int) $data['total_cents']);
        $name = trim((string) ($data['name'] ?? ''));
        $this->orders->approveBudget($order, $name !== '' ? "Aprovado por: {$name}" : null, null);

        return $this->present($order->fresh());
    }

    public function reject(Request $request, string $token): JsonResponse
    {
        $order = BudgetLink::resolve($token);
        $data = $request->validate([
            'total_cents' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->ensureCanDecide($order, (int) $data['total_cents']);
        $reason = trim((string) ($data['reason'] ?? ''));
        // Pelo link o cliente só recusa: a oficina decide se revisa ou cancela
        $this->orders->rejectBudget($order, $reason !== '' ? "Motivo: {$reason}" : null, false, null);

        return $this->present($order->fresh());
    }

    private function ensureCanDecide(ServiceOrder $order, int $seenTotal): void
    {
        if (! $this->orders->awaitsDecision($order) || $this->lastBudgetEvent($order) === 'budget_rejected') {
            throw ValidationException::withMessages(['budget' => 'Este orçamento não está mais aguardando resposta.']);
        }
        if ($seenTotal !== $order->total_cents) {
            throw ValidationException::withMessages(['budget' => 'O orçamento foi atualizado pela oficina. Confira os novos valores.']);
        }
    }

    /** Último evento de orçamento: o cliente que recusou só decide de novo depois de um novo envio. */
    private function lastBudgetEvent(ServiceOrder $order): ?string
    {
        return $order->events()->reorder()
            ->whereIn('type', ['budget_sent', 'budget_approved', 'budget_rejected'])
            ->latest('id')
            ->value('type');
    }

    private function present(ServiceOrder $order): JsonResponse
    {
        $order->load(['customer', 'vehicle', 'items', 'parts']);
        $shop = ShopSettings::get();
        $last = $this->lastBudgetEvent($order);

        $state = match (true) {
            $order->isBudgetApproved() && ! $order->budgetChangedAfterApproval() => 'approved',
            $last === 'budget_rejected' => 'rejected',
            $this->orders->awaitsDecision($order) => 'awaiting',
            default => 'unavailable',
        };

        return response()->json(['data' => [
            'state' => $state,
            'shop' => [
                'name' => $shop['name'],
                'phone' => $shop['phone'],
                'budget_notes' => $shop['budget_notes'],
                'warranty_text' => $shop['warranty_text'],
                'budget_validity_days' => $shop['budget_validity_days'],
            ],
            'order' => [
                'number' => $order->number(),
                'status_label' => $order->status->label(),
                'created_at' => $order->created_at?->toIso8601String(),
                'customer_first_name' => Str::of($order->customer->trade_name ?: $order->customer->name)->explode(' ')->first(),
                'vehicle' => [
                    'brand' => $order->vehicle->brand,
                    'model' => $order->vehicle->model,
                    'model_year' => $order->vehicle->model_year,
                    'plate' => $order->vehicle->plate,
                ],
                'complaint' => $order->complaint,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->name,
                    'notes' => $item->notes,
                    'price_cents' => $item->price_cents,
                    'warranty' => $item->warranty_of_item_id !== null,
                ]),
                'parts' => $order->parts->map(fn ($part) => [
                    'name' => $part->name,
                    'quantity' => (float) $part->quantity,
                    'unit_price_cents' => $part->unit_price_cents,
                    'total_cents' => $part->totalCents(),
                ]),
                'labor_total_cents' => $order->labor_total_cents,
                'parts_total_cents' => $order->parts_total_cents,
                'discount_cents' => $order->discount_cents,
                'total_cents' => $order->total_cents,
                'total_label' => Money::format($order->total_cents),
                'budget_approved_at' => $order->budget_approved_at?->toIso8601String(),
                'paid_cents' => $order->paid_cents,
                'balance_cents' => max(0, $order->balanceCents()),
            ],
            // Aprovado e com saldo: o cliente já pode pagar pelo Pix (estático; a oficina confere e dá baixa)
            'pix' => $state === 'approved' ? PixCharge::forOrder($order) : null,
        ]]);
    }
}
