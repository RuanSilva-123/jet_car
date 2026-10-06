<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Enums\VehicleType;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->numberBetween(2010, 2026);

        return [
            'customer_id' => Customer::factory(),
            'type' => VehicleType::Car,
            'brand' => 'GM - Chevrolet',
            'model' => 'ONIX HATCH LT 1.0 12V Flex 5p Mec.',
            'model_year' => $year,
            'manufacture_year' => $year,
            'fuel' => FuelType::Flex,
            // Placa Mercosul: ABC1D23
            'plate' => Str::upper(fake()->unique()->bothify('???#?##')),
            'color' => 'Prata',
            'mileage' => fake()->numberBetween(0, 200000),
        ];
    }
}
