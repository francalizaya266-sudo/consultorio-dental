<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\PatientRelationship;
use App\Enums\Sex;
use App\Models\Account;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'relationship' => PatientRelationship::Self,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName().' '.fake()->lastName(),
            'document_type' => DocumentType::Dni,
            'document_number' => fake()->unique()->numerify('########'),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years'),
            'sex' => fake()->randomElement(Sex::cases()),
            'email' => fake()->optional()->safeEmail(),
            'district' => fake()->randomElement(['Miraflores', 'San Isidro', 'Surco', 'San Borja', 'Lince', 'Jesús María']),
        ];
    }

    /** Familiar a cargo (por ejemplo, un hijo menor sin celular propio). */
    public function dependentOf(Account $account, PatientRelationship $relationship = PatientRelationship::Child): static
    {
        return $this->state(fn () => [
            'account_id' => $account->getKey(),
            'relationship' => $relationship,
            'birth_date' => fake()->dateTimeBetween('-12 years', '-3 years'),
            'document_number' => null,
            'document_type' => null,
        ]);
    }
}
