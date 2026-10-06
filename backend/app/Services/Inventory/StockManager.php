<?php

namespace App\Services\Inventory;

use App\Models\Part;
use App\Models\ServiceOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Movimentação de estoque. Toda mudança de quantidade passa por aqui e gera um registro
 * em stock_movements com o saldo resultante. A OS pode deixar o estoque negativo
 * (peça usada antes de lançar a compra): isso aparece como estoque baixo.
 */
class StockManager
{
    /** Compra/entrada de mercadoria. Com custo informado, atualiza o custo da peça. */
    public function entry(Part $part, float $quantity, ?int $unitCostCents, ?string $notes, User $actor): StockMovement
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantidade deve ser maior que zero.']);
        }

        return DB::transaction(function () use ($part, $quantity, $unitCostCents, $notes, $actor) {
            $movement = $this->move($part, $quantity, 'entry', $actor, notes: $notes, unitCostCents: $unitCostCents);

            if ($unitCostCents !== null) {
                $part->forceFill(['cost_cents' => $unitCostCents])->save();
            }

            return $movement;
        });
    }

    /** Contagem de inventário: define a quantidade real em estoque. */
    public function adjust(Part $part, float $countedQuantity, ?string $notes, User $actor): StockMovement
    {
        return DB::transaction(function () use ($part, $countedQuantity, $notes, $actor) {
            $current = (float) Part::whereKey($part->id)->lockForUpdate()->value('stock_quantity');
            $delta = round($countedQuantity - $current, 2);

            if ($delta == 0.0) {
                throw ValidationException::withMessages(['quantity' => 'A quantidade contada é igual à do estoque.']);
            }

            return $this->move($part, $delta, 'adjustment', $actor, notes: $notes);
        });
    }

    /** Baixa (quantidade positiva) ou devolução (negativa) de peça usada numa OS. */
    public function forOrder(Part $part, float $quantityUsed, ServiceOrder $order, ?User $actor): ?StockMovement
    {
        $delta = round(-$quantityUsed, 2);
        if ($delta == 0.0) {
            return null;
        }

        return $this->move($part, $delta, $delta < 0 ? 'order_out' : 'order_return', $actor, $order);
    }

    private function move(
        Part $part,
        float $delta,
        string $type,
        ?User $actor,
        ?ServiceOrder $order = null,
        ?string $notes = null,
        ?int $unitCostCents = null,
    ): StockMovement {
        return DB::transaction(function () use ($part, $delta, $type, $actor, $order, $notes, $unitCostCents) {
            $locked = Part::withTrashed()->whereKey($part->id)->lockForUpdate()->firstOrFail();
            $balance = round((float) $locked->stock_quantity + $delta, 2);
            $locked->forceFill(['stock_quantity' => $balance])->save();
            $part->setRawAttributes($locked->getAttributes(), true);

            return $locked->movements()->create([
                'type' => $type,
                'quantity' => $delta,
                'balance_after' => $balance,
                'unit_cost_cents' => $unitCostCents,
                'service_order_id' => $order?->id,
                'user_id' => $actor?->id,
                'notes' => $notes,
            ]);
        });
    }
}
