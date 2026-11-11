<?php

namespace Database\Factories;

use App\Models\DeskNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeskNote>
 */
class DeskNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->randomElement(['Llamar al laboratorio por la corona', 'Pedir más recibos a la imprenta', 'Confirmar la visita del técnico del sillón']),
            'due_on' => today(),
            'created_by_user_id' => User::factory()->reception(),
        ];
    }

    public function done(?User $by = null): static
    {
        return $this->state(fn () => ['done_at' => now(), 'done_by_user_id' => $by?->getKey()]);
    }
}
