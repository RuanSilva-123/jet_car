<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Support\ShopSettings;
use Database\Factories\ServiceOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable(['mileage', 'complaint', 'notes', 'expected_at', 'discount_cents'])]
class ServiceOrder extends Model
{
    /** @use HasFactory<ServiceOrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceOrderStatus::class,
            'mileage' => 'integer',
            'expected_at' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'canceled_at' => 'datetime',
            'labor_total_cents' => 'integer',
            'parts_total_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'survey_score' => 'integer',
            'survey_answered_at' => 'datetime',
            'budget_sent_at' => 'datetime',
            'budget_approved_at' => 'datetime',
            'budget_approved_total_cents' => 'integer',
        ];
    }

    public function isBudgetApproved(): bool
    {
        return $this->budget_approved_at !== null;
    }

    /**
     * Orçamento mudou depois que o cliente aprovou: valor diferente do aprovado
     * ou serviço/peça nova ainda sem valor.
     */
    public function budgetChangedAfterApproval(): bool
    {
        return $this->isBudgetApproved()
            && ($this->budget_approved_total_cents !== $this->total_cents || $this->unpricedCount() > 0);
    }

    /** Serviços e peças ainda com valor "a definir". */
    public function unpricedCount(): int
    {
        $items = $this->relationLoaded('items')
            ? $this->items->whereNull('price_cents')->count()
            : $this->items()->whereNull('price_cents')->count();
        $parts = $this->relationLoaded('parts')
            ? $this->parts->whereNull('unit_price_cents')->count()
            : $this->parts()->whereNull('unit_price_cents')->count();

        return $items + $parts;
    }

    /** Quanto falta receber (negativo = cliente pagou a mais). */
    public function balanceCents(): int
    {
        return $this->total_cents - $this->paid_cents;
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::for($this->total_cents, $this->paid_cents);
    }

    /** Número exibido para o cliente: OS 00042. */
    public function number(): string
    {
        return str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Cliente/veículo continuam visíveis no histórico mesmo se forem excluídos depois.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ServiceOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ServiceOrderPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(ServiceOrderPart::class)->orderBy('position')->orderBy('id');
    }

    /**
     * OS original, quando esta é um retorno em garantia.
     *
     * @return BelongsTo<ServiceOrder, $this>
     */
    public function warrantyOf(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'warranty_of_id');
    }

    /**
     * Retornos em garantia desta OS.
     *
     * @return HasMany<ServiceOrder, $this>
     */
    public function warrantyReturns(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'warranty_of_id')->orderBy('id');
    }

    /** Fim da garantia (entrega + prazo dos dados da oficina); null se não foi entregue. */
    public function warrantyUntil(): ?Carbon
    {
        $days = (int) ShopSettings::get()['warranty_days'];

        return $this->delivered_at && $days > 0 ? $this->delivered_at->copy()->addDays($days)->endOfDay() : null;
    }

    /**
     * @return HasOne<ServiceOrderInspection, $this>
     */
    public function inspection(): HasOne
    {
        return $this->hasOne(ServiceOrderInspection::class);
    }

    /**
     * @return HasMany<ServiceOrderPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ServiceOrderPhoto::class)->orderBy('id');
    }

    /**
     * @return HasMany<ServiceOrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ServiceOrderPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /**
     * @return HasMany<ServiceOrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ServiceOrderEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
