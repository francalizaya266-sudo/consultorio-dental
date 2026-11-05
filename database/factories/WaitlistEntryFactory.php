<?php

namespace Database\Factories;

use App\Enums\DayPeriod;
use App\Enums\WaitlistStatus;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
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
            'treatment_id' => Treatment::factory(),
            'earliest_date' => today(),
            'preferred_period' => DayPeriod::Any,
            'status' => WaitlistStatus::Waiting,
        ];
    }

    public function offered(): static
    {
        return $this->state(fn () => ['status' => WaitlistStatus::Offered, 'offered_at' => now()]);
    }

    public function removed(): static
    {
        return $this->state(fn () => ['status' => WaitlistStatus::Removed]);
    }
}
