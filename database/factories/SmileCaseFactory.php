<?php

namespace Database\Factories;

use App\Models\SmileCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmileCase>
 */
class SmileCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Blanqueamiento', 'Carillas de resina', 'Ortodoncia']).' en '.fake()->numberBetween(2, 12).' semanas',
            'before_path' => 'casos/antes-'.fake()->uuid().'.jpg',
            'after_path' => 'casos/despues-'.fake()->uuid().'.jpg',
            'consent_confirmed_at' => now(),
            'is_published' => true,
        ];
    }
}
