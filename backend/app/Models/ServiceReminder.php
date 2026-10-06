<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cliente a contatar para refazer um serviço periódico (revisão, troca de óleo...). */
#[Fillable([
    'vehicle_id', 'customer_id', 'labor_service_id', 'source_item_id', 'service_name',
    'last_done_at', 'last_mileage', 'due_at', 'due_mileage', 'status', 'notes',
])]
class ServiceReminder extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReminderStatus::class,
            'last_done_at' => 'date',
            'due_at' => 'date',
            'last_mileage' => 'integer',
            'due_mileage' => 'integer',
            'contacted_at' => 'datetime',
        ];
    }

    /** Já passou da data ou da quilometragem (pela última km conhecida do veículo). */
    public function isOverdue(): bool
    {
        $byDate = $this->due_at !== null && $this->due_at->isPast();
        $mileage = $this->relationLoaded('vehicle') ? $this->vehicle?->mileage : null;
        $byKm = $this->due_mileage !== null && $mileage !== null && $mileage >= $this->due_mileage;

        return $byDate || $byKm;
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }
}
