<?php

namespace Tests\Feature\Models;

use App\Models\Patient;
use Tests\TestCase;

class PatientShortNameTest extends TestCase
{
    public function test_the_short_name_is_the_first_name_and_the_first_surname_with_its_particles(): void
    {
        $this->assertSame('María Quispe', Patient::make(['first_name' => 'María', 'last_name' => 'Quispe Huamán'])->short_name);
        $this->assertSame('Ana Acosta', Patient::make(['first_name' => 'Ana  Paula', 'last_name' => 'Acosta Gaona'])->short_name);
        $this->assertSame('Benjamín Del Valle', Patient::make(['first_name' => 'Benjamín', 'last_name' => 'Del Valle Salinas'])->short_name);
        $this->assertSame('Laura de la Cruz', Patient::make(['first_name' => 'Laura', 'last_name' => 'de la Cruz Rojas'])->short_name);
        $this->assertSame('Rosa', Patient::make(['first_name' => 'Rosa', 'last_name' => ''])->short_name);
    }
}
