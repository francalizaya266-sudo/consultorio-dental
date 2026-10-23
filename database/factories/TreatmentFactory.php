<?php

namespace Database\Factories;

use App\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Treatment>
 */
class TreatmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $name = fake()->unique()->randomElement(['Limpieza dental', 'Curación con resina', 'Extracción simple', 'Blanqueamiento', 'Endodoncia', 'Control de ortodoncia']).' '.fake()->unique()->numberBetween(1, 9999),
            'slug' => Str::slug($name),
            'summary' => fake()->sentence(),
            'price_from' => fake()->randomElement([80, 120, 150, 250, 450, 800]),
            'duration_minutes' => fake()->randomElement([30, 45, 60]),
            'sessions_label' => '1',
            'online_bookable' => true,
            'is_public' => true,
            'is_active' => true,
        ];
    }

    public function evaluation(): static
    {
        return $this->state(fn () => [
            'name' => 'Evaluación',
            'slug' => 'evaluacion-'.Str::random(6),
            'duration_minutes' => 30,
            'is_evaluation' => true,
            'online_bookable' => true,
        ]);
    }

    /** Tratamiento que solo agenda el doctor (no se reserva en la web). */
    public function clinicOnly(): static
    {
        return $this->state(fn () => ['online_bookable' => false]);
    }
}
