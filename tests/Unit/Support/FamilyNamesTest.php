<?php

namespace Tests\Unit\Support;

use App\Models\Patient;
use App\Support\FamilyNames;
use PHPUnit\Framework\TestCase;

class FamilyNamesTest extends TestCase
{
    public function test_each_person_goes_by_first_name_when_nobody_else_has_it(): void
    {
        $family = [$this->patient(1, 'María', 'Quispe Huamán'), $this->patient(2, 'Mateo', 'Quispe Huamán')];

        $this->assertSame([1 => 'María', 2 => 'Mateo'], FamilyNames::for($family));
    }

    public function test_a_repeated_first_name_adds_the_first_surname_ignoring_accents_and_capitals(): void
    {
        $family = [
            $this->patient(1, 'María', 'Quispe Huamán'),
            $this->patient(2, 'MARIA', 'Flores Ticona'),
            $this->patient(3, 'Rosa', 'Quispe Huamán'),
        ];

        $this->assertSame([1 => 'María Quispe', 2 => 'MARIA Flores', 3 => 'Rosa'], FamilyNames::for($family));
    }

    public function test_the_same_first_name_and_surname_falls_back_to_the_full_name(): void
    {
        $family = [$this->patient(1, 'María', 'Quispe Huamán'), $this->patient(2, 'María', 'Quispe Mamani')];

        $this->assertSame([1 => 'María Quispe Huamán', 2 => 'María Quispe Mamani'], FamilyNames::for($family));
    }

    private function patient(int $id, string $firstName, string $lastName): Patient
    {
        return (new Patient(['first_name' => $firstName, 'last_name' => $lastName]))->forceFill(['id' => $id]);
    }
}
