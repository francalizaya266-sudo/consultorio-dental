<?php

namespace Tests\Unit\Support;

use App\Support\Initials;
use PHPUnit\Framework\TestCase;

class InitialsTest extends TestCase
{
    public function test_initials_skip_the_title_and_take_the_first_surname(): void
    {
        $this->assertSame('JV', Initials::of('Dr. Jassmanny Neil Vega Jaen'));
        $this->assertSame('AT', Initials::of('Dra. Ana Torres Ruiz'));
        $this->assertSame('LR', Initials::of('Dr(a). Luis  Ramos'));
        $this->assertSame('ÑQ', Initials::of('ñahui quispe'));
        $this->assertSame('R', Initials::of('Recepción'));
        $this->assertSame('', Initials::of(''));
    }
}
