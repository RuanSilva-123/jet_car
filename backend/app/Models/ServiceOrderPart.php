<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Peça do orçamento da OS. */
#[Fillable(['name', 'part_number', 'quantity', 'unit_price_cents', 'position'])]
class ServiceOrderPart extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    /** Total da linha; null enquanto o valor unitário não foi definido. */
    public function totalCents(): ?int
    {
        return $this->unit_price_cents === null ? null : Money::multiply($this->quantity, $this->unit_price_cents);
    }

    /**
     * @return BelongsTo<ServiceOrder, $this>
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }
}
