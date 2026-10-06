<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_type' => PersonType::Individual,
            'name' => fake()->name(),
            'document' => self::cpf(),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'phone' => '119'.fake()->numerify('########'),
            'phone_is_whatsapp' => true,
            'email' => fake()->unique()->safeEmail(),
            'zip_code' => '01001000',
            'street' => 'Praça da Sé',
            'number' => (string) fake()->numberBetween(1, 999),
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'person_type' => PersonType::Company,
            'name' => fake()->company().' LTDA',
            'trade_name' => fake()->company(),
            'document' => self::cnpj(),
            'birth_date' => null,
        ]);
    }

    /** CPF válido aleatório (somente dígitos). */
    public static function cpf(): string
    {
        $digits = array_map(fn () => random_int(0, 9), range(1, 9));

        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += $digits[$i] * ($position + 1 - $i);
            }
            $digits[] = ($sum * 10) % 11 % 10;
        }

        return implode('', $digits);
    }

    /** CNPJ numérico válido aleatório (somente dígitos). */
    public static function cnpj(): string
    {
        $base = array_map(fn () => random_int(0, 9), range(1, 8));
        $digits = [...$base, 0, 0, 0, 1];
        $weights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([12, 13] as $length) {
            $sum = 0;
            $offset = 13 - $length;
            for ($i = 0; $i < $length; $i++) {
                $sum += $digits[$i] * $weights[$i + $offset];
            }
            $remainder = $sum % 11;
            $digits[] = $remainder < 2 ? 0 : 11 - $remainder;
        }

        return implode('', $digits);
    }
}
