<?php

namespace Database\Factories;

use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
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
            'attended_at' => now(),
            'chief_complaint' => fake()->randomElement(['Dolor en pieza inferior derecha', 'Control y limpieza', 'Sensibilidad al frío']),
            'clinical_exam' => 'Mucosas de aspecto normal.',
            'procedures' => fake()->sentence(),
        ];
    }
}
