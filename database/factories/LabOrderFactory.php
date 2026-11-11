<?php

namespace Database\Factories;

use App\Models\LabOrder;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabOrder>
 */
class LabOrderFactory extends Factory
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
            'description' => fake()->randomElement(['Corona de zirconio', 'Prótesis parcial removible', 'Férula de descarga']),
            'laboratory' => fake()->randomElement(['Laboratorio Dental Lima', 'DentalArt']),
            'sent_on' => today(),
            'due_on' => today()->addDays(7),
            'cost' => 180,
        ];
    }

    public function received(): static
    {
        return $this->state(fn () => ['received_on' => today()]);
    }
}
