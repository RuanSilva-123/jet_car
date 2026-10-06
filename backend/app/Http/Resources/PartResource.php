<?php

namespace App\Http\Resources;

use App\Models\Part;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Part
 */
class PartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'part_number' => $this->part_number,
            'brand' => $this->brand,
            'unit' => $this->unit,
            'cost_cents' => $this->cost_cents,
            'price_cents' => $this->price_cents,
            // Margem sobre o preço de venda, em %
            'margin_percent' => $this->cost_cents !== null && $this->price_cents
                ? round(($this->price_cents - $this->cost_cents) / $this->price_cents * 100, 1)
                : null,
            'stock_quantity' => (float) $this->stock_quantity,
            'min_stock' => (float) $this->min_stock,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
