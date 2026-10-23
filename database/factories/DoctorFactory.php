<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Doctor]),
            'name' => $name = 'Dr(a). '.fake()->firstName().' '.fake()->lastName(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'specialty' => fake()->randomElement(['Odontología general', 'Ortodoncia', 'Endodoncia', 'Odontopediatría']),
            'cop_number' => (string) fake()->numberBetween(10000, 50000),
            'agenda_color' => fake()->hexColor(),
            'is_public' => true,
            'accepts_online_booking' => true,
            'is_active' => true,
        ];
    }

    /** Atiende de lunes a sábado en el horario indicado (por defecto 9:00 a 13:00 y 15:00 a 19:00). */
    public function withSchedule(array $ranges = [['09:00', '13:00'], ['15:00', '19:00']], array $weekdays = [1, 2, 3, 4, 5, 6]): static
    {
        return $this->afterCreating(function ($doctor) use ($ranges, $weekdays) {
            foreach ($weekdays as $weekday) {
                foreach ($ranges as [$start, $end]) {
                    DoctorSchedule::create([
                        'doctor_id' => $doctor->getKey(),
                        'weekday' => $weekday,
                        'starts_at' => $start,
                        'ends_at' => $end,
                    ]);
                }
            }
        });
    }
}
