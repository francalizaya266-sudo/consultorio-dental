<?php

namespace Database\Factories;

use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPackageItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TreatmentPackageItem>
 */
class TreatmentPackageItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'treatment_package_id' => TreatmentPackage::factory(),
            'treatment_id' => Treatment::factory(),
            'quantity' => 1,
            'sessions_planned' => 1,
        ];
    }
}
