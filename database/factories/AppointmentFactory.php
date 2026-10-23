<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
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
            'doctor_id' => Doctor::factory(),
            'treatment_id' => Treatment::factory(),
            'starts_at' => $start = now()->addDays(3)->setTime(10, 0),
            'ends_at' => $start->copy()->addMinutes(30),
            'buffer_minutes' => 10,
            'status' => AppointmentStatus::Pending,
            'booked_via' => BookingChannel::Web,
        ];
    }

    /** Cita en un horario concreto con la duración indicada. */
    public function at(string $startsAt, int $minutes = 30, int $buffer = 10): static
    {
        return $this->state(fn () => [
            'starts_at' => $start = now()->parse($startsAt),
            'ends_at' => $start->copy()->addMinutes($minutes),
            'buffer_minutes' => $buffer,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => AppointmentStatus::Confirmed, 'confirmed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now()]);
    }
}
