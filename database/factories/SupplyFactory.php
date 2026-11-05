<?php

namespace Database\Factories;

use App\Enums\SupplyCategory;
use App\Models\Supply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supply>
 */
class SupplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Guantes de nitrilo M', 'Baberos', 'Eyectores', 'Pasta profiláctica', 'Gasas', 'Algodón en rollos', 'Ácido grabador', 'Adhesivo', 'Hilo dental']).' '.fake()->numerify('##'),
            'category' => SupplyCategory::Biosafety,
            'unit' => 'unidad',
            'min_stock' => 10,
            'unit_cost' => 1.5,
            'is_active' => true,
        ];
    }

    /** Implante: cada unidad con su número de serie. */
    public function serialized(): static
    {
        return $this->state([
            'name' => 'Implante '.fake()->numerify('#.#').' x '.fake()->numerify('##').' mm',
            'category' => SupplyCategory::Implants,
            'min_stock' => 2,
            'unit_cost' => 450,
            'tracks_serial' => true,
        ]);
    }
}
