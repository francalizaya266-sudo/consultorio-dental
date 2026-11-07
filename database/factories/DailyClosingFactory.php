<?php

namespace Database\Factories;

use App\Models\DailyClosing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyClosing>
 */
class DailyClosingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => today(),
            'opening_cash' => 100,
            'expected_cash' => 300,
            'counted_cash' => 300,
            'collected' => 200,
            'pending' => 0,
        ];
    }
}
