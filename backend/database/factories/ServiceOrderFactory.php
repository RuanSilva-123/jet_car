<?php

namespace Database\Factories;

use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOrder>
 */
class ServiceOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'customer_id' => fn (array $attributes) => Vehicle::find($attributes['vehicle_id'])->customer_id,
            'status' => ServiceOrderStatus::Open,
            'mileage' => null,
        ];
    }

    public function status(ServiceOrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
