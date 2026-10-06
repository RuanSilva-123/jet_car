<?php

namespace App\Models;

use App\Enums\IncomeCategory;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada do caixa que não vem de OS (prevista ou já recebida). */
#[Fillable(['description', 'category', 'amount_cents', 'expected_on', 'notes'])]
class Income extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => IncomeCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'expected_on' => 'date',
            'received_at' => 'date',
        ];
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    /**
     * @param  Builder<Income>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('received_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
