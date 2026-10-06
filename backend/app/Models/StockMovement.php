<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada/saída de estoque. Tipos: entry (compra), adjustment (contagem),
 * order_out (baixa na OS), order_return (devolução da OS).
 */
#[Fillable(['type', 'quantity', 'balance_after', 'unit_cost_cents', 'service_order_id', 'user_id', 'notes'])]
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'entry' => 'Entrada',
        'adjustment' => 'Ajuste de inventário',
        'order_out' => 'Saída para OS',
        'order_return' => 'Devolução da OS',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'unit_cost_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Part, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ServiceOrder, $this>
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
