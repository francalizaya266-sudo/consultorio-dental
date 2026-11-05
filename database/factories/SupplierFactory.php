<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Dental', 'Depósito', 'Distribuidora']).' '.fake()->lastName(),
            'contact_name' => fake()->firstName(),
            'phone' => '9'.fake()->numerify('########'),
            'is_active' => true,
        ];
    }
}
