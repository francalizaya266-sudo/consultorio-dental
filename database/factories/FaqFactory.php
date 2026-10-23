<?php

namespace Database\Factories;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => '¿'.rtrim(fake()->sentence(6), '.').'?',
            'answer' => fake()->paragraph(),
            'is_published' => true,
            'sort_order' => 0,
        ];
    }
}
