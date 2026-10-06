<?php

namespace App\Models;

use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Peça do estoque (catálogo com custo, preço de venda e quantidade). */
#[Fillable(['name', 'part_number', 'brand', 'unit', 'cost_cents', 'price_cents', 'min_stock', 'is_active', 'notes'])]
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_cents' => 'integer',
            'price_cents' => 'integer',
            'stock_quantity' => 'decimal:2',
            'min_stock' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Estoque no mínimo definido (ou negativo: saiu mais do que entrou). */
    public function isLowStock(): bool
    {
        $stock = (float) $this->stock_quantity;
        $min = (float) $this->min_stock;

        return $stock < 0 || ($min > 0 && $stock <= $min);
    }

    /**
     * @param  Builder<Part>  $query
     */
    public function scopeLowStock(Builder $query): void
    {
        $query->where('is_active', true)->where(fn (Builder $query) => $query
            ->where('stock_quantity', '<', 0)
            ->orWhere(fn (Builder $query) => $query->where('min_stock', '>', 0)->whereColumn('stock_quantity', '<=', 'min_stock')));
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
