<?php

namespace Database\Factories;

use App\Models\TreatmentPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TreatmentPackage>
 */
class TreatmentPackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Paquete '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            'price' => fake()->randomElement([299, 450, 990]),
            'allows_upfront' => true,
            'upfront_discount' => 0,
            'allows_installments' => false,
            'down_payment' => 0,
            'is_active' => true,
        ];
    }

    /** Inicial y cuotas mensuales iguales. */
    public function inInstallments(float $downPayment = 0, int $installments = 3): static
    {
        return $this->state(fn () => [
            'allows_installments' => true,
            'down_payment' => $downPayment,
            'installments' => $installments,
        ]);
    }
}
