<?php

namespace Database\Factories;

use App\Models\Part;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Part>
 */
class PartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'part_number' => strtoupper(fake()->bothify('??-####')),
            'unit' => 'un',
            'cost_cents' => 5000,
            'price_cents' => 8000,
            'min_stock' => 0,
            'is_active' => true,
        ];
    }

    public function stock(float $quantity, float $min = 0): static
    {
        return $this->state(fn () => ['min_stock' => $min])->afterCreating(function (Part $part) use ($quantity) {
            $part->forceFill(['stock_quantity' => $quantity])->save();
        });
    }
}
