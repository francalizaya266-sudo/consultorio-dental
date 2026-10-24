<?php

namespace Tests\Unit\Services;

use App\Services\Clinical\AllergyCheck;
use PHPUnit\Framework\TestCase;

class AllergyCheckTest extends TestCase
{
    public function test_a_penicillin_allergy_flags_amoxicillin_and_warns_about_cephalosporins(): void
    {
        $this->assertSame('Cuidado con Amoxicilina: el paciente tiene alergia a la penicilina.', AllergyCheck::warningFor('Alérgico a la PENICILINA', 'Amoxicilina 500 mg'));
        $this->assertSame('Cuidado con Cefalexina: puede dar reacción cruzada con su alergia a la penicilina.', AllergyCheck::warningFor('penicilina', 'Cefalexina 500 mg'));
    }

    public function test_an_nsaid_allergy_flags_ibuprofen_but_not_paracetamol(): void
    {
        $this->assertNotNull(AllergyCheck::warningFor('AINES', 'Ibuprofeno 400 mg'));
        $this->assertNotNull(AllergyCheck::warningFor('aspirina', 'Ketorolaco 10 mg'));
        $this->assertNull(AllergyCheck::warningFor('aspirina', 'Paracetamol 500 mg'));
    }

    public function test_nothing_is_flagged_without_a_real_allergy(): void
    {
        $this->assertNull(AllergyCheck::warningFor('Ninguna', 'Amoxicilina 500 mg'));
        $this->assertNull(AllergyCheck::warningFor(null, 'Amoxicilina 500 mg'));
        $this->assertNull(AllergyCheck::warningFor('Penicilina', ''));
    }
}
