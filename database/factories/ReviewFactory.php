<?php

namespace Database\Factories;

use App\Enums\ReviewSource;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'author_name' => fake()->firstName().' '.fake()->lastName(),
            'rating' => 5,
            'body' => fake()->paragraph(),
            'source' => ReviewSource::Google,
            'reviewed_on' => fake()->dateTimeBetween('-1 year'),
            'is_published' => true,
        ];
    }
}
