<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '+519'.fake()->unique()->numerify('########'),
            'privacy_accepted_at' => now(),
            'accepts_promotions' => fake()->boolean(),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['phone_verified_at' => now()]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['blocked_at' => now(), 'blocked_reason' => 'Inasistencias repetidas']);
    }
}
