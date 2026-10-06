<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conta a pagar (uma parcela, quando parcelada). */
#[Fillable(['description', 'supplier_id', 'category', 'amount_cents', 'due_date', 'document_number', 'notes'])]
class Bill extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'due_date' => 'date',
            'paid_at' => 'date',
            'installment_number' => 'integer',
            'installment_count' => 'integer',
        ];
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date->lt(today());
    }

    /**
     * @param  Builder<Bill>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('paid_at');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<RecurringBill, $this>
     */
    public function recurringBill(): BelongsTo
    {
        return $this->belongsTo(RecurringBill::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
