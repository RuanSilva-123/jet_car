<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Horário marcado na agenda da oficina. */
#[Fillable(['customer_id', 'contact_name', 'contact_phone', 'vehicle_id', 'vehicle_description', 'scheduled_at', 'duration_minutes', 'notes', 'service_reminder_id'])]
class Appointment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    /** Nome para a agenda: o do cadastro ou o digitado (quem ainda não é cliente). */
    public function displayName(): string
    {
        return $this->customer
            ? ($this->customer->trade_name ?: $this->customer->name)
            : (string) $this->contact_name;
    }

    /** Veículo para a agenda: o cadastrado ou a descrição digitada. */
    public function vehicleLabel(): ?string
    {
        return $this->vehicle
            ? trim($this->vehicle->brand.' '.$this->vehicle->model)
            : $this->vehicle_description;
    }

    /**
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
     * @return BelongsTo<ServiceOrder, $this>
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
