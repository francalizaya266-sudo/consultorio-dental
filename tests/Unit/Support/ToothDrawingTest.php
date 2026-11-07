<?php

namespace Tests\Unit\Support;

use App\Enums\Dentition;
use App\Support\Teeth;
use App\Support\ToothDrawing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ToothDrawingTest extends TestCase
{
    public function test_every_tooth_of_every_dentition_has_a_crown_roots_and_canals(): void
    {
        foreach (Dentition::cases() as $dentition) {
            foreach (Teeth::all($dentition) as $tooth) {
                $shape = ToothDrawing::shape($tooth);

                $this->assertStringStartsWith('M ', $shape['crown'], "Pieza {$tooth}");
                $this->assertStringEndsWith(' Z', $shape['roots'], "Pieza {$tooth}");
                $this->assertNotEmpty($shape['canals'], "Pieza {$tooth}");
                $this->assertNotEmpty($shape['threads'], "Pieza {$tooth}");
                $this->assertGreaterThan($shape['crownHeight'], $shape['height']);
            }
        }
    }

    /** @return array<string, array{0: int, 1: int, 2: bool}> */
    public static function roots(): array
    {
        return [
            'incisivo central superior' => [11, 1, false],
            'canino inferior' => [43, 1, false],
            'primer premolar superior: dos raíces' => [14, 2, false],
            'primer molar superior: tres, una palatina' => [26, 3, true],
            'primer molar inferior: dos' => [36, 2, false],
            'molar de leche superior: tres' => [55, 3, true],
            'molar de leche inferior: dos' => [84, 2, false],
        ];
    }

    #[DataProvider('roots')]
    public function test_each_tooth_type_has_its_roots(int $tooth, int $canals, bool $palatal): void
    {
        $shape = ToothDrawing::shape($tooth);

        $this->assertCount($canals, $shape['canals']);
        $this->assertSame($palatal, $shape['palatal'] !== null);
    }

    public function test_a_row_is_as_tall_as_its_tallest_tooth(): void
    {
        $upper = Teeth::rows(Dentition::Permanent)[0];

        $this->assertSame(ToothDrawing::shape(13)['height'], ToothDrawing::tallest($upper));
        $this->assertGreaterThan(ToothDrawing::tallest(Teeth::rows(Dentition::Primary)[0]), ToothDrawing::tallest($upper));
    }

    public function test_each_tooth_has_its_name(): void
    {
        $this->assertSame('Incisivo central superior derecho', Teeth::name(11));
        $this->assertSame('Primer molar inferior izquierdo', Teeth::name(36));
        $this->assertSame('Tercer molar inferior derecho', Teeth::name(48));
        $this->assertSame('Canino superior derecho (de leche)', Teeth::name(53));
        $this->assertSame('Segundo molar inferior izquierdo (de leche)', Teeth::name(75));
    }
}
