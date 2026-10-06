<?php

namespace Database\Factories;

use App\Enums\ServiceCategory;
use App\Models\LaborService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaborService>
 */
class LaborServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Serviço '.fake()->unique()->numerify('####'),
            'category' => fake()->randomElement(ServiceCategory::cases()),
            'description' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
