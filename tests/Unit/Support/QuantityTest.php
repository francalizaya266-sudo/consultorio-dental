<?php

namespace Tests\Unit\Support;

use App\Support\Quantity;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    public function test_quantities_read_as_they_are_said(): void
    {
        $this->assertSame('1 carpule', Quantity::format(1, 'carpule'));
        $this->assertSame('12 carpules', Quantity::format(12, 'carpule'));
        $this->assertSame('3 pares', Quantity::format(3, 'par'));
        $this->assertSame('5 unidades', Quantity::format(5, 'unidad'));
        $this->assertSame('3 kits', Quantity::format(3, 'kit'));
        $this->assertSame('2.5 ml', Quantity::format(2.5, 'ml'));
        $this->assertSame('0.5 g', Quantity::format(0.5, 'g'));
        $this->assertSame('−2 cajas', Quantity::format(-2, 'caja'));
    }
}
