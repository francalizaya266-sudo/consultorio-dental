<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'amount' => fake()->randomElement([80, 120, 150, 250]),
            'method' => fake()->randomElement(PaymentMethod::cases()),
            'paid_at' => now(),
        ];
    }
}
