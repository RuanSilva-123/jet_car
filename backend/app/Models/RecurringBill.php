<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Despesa fixa: gera uma conta a pagar por mês. */
#[Fillable(['description', 'supplier_id', 'category', 'amount_cents', 'day_of_month', 'starts_on', 'ends_on', 'is_active', 'notes'])]
class RecurringBill extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount_cents' => 'integer',
            'day_of_month' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** Vencimento no mês de $month (dia 31 em mês de 30 dias = último dia). */
    public function dueDateIn(Carbon $month): Carbon
    {
        $start = $month->copy()->startOfMonth();

        return $start->copy()->day(min($this->day_of_month, $start->daysInMonth));
    }

    /** Vale no mês (ativa e dentro do período de início/fim). */
    public function appliesTo(Carbon $month): bool
    {
        return $this->is_active
            && $this->starts_on->copy()->startOfMonth()->lte($month->copy()->startOfMonth())
            && ($this->ends_on === null || $this->ends_on->gte($month->copy()->startOfMonth()));
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
