<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'spent_at' => now(),
            'category' => ExpenseCategory::Other,
            'description' => fake()->randomElement(['Movilidad', 'Útiles de oficina', 'Agua de mesa']),
            'amount' => fake()->randomElement([10, 20, 35, 50]),
            'method' => PaymentMethod::Cash,
        ];
    }
}
