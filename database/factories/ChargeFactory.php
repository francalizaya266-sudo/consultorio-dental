<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'description' => fake()->randomElement(['Limpieza dental', 'Curación con resina', 'Evaluación']),
            'amount' => fake()->randomElement([50, 120, 150, 250]),
            'discount' => 0,
            'charged_at' => now(),
        ];
    }
}
