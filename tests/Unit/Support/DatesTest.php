<?php

namespace Tests\Unit\Support;

use App\Support\Dates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatesTest extends TestCase
{
    public static function durations(): array
    {
        return [
            'minutos' => [45, "45\u{00A0}min"],
            'una hora justa' => [60, "1\u{00A0}h"],
            'horas y minutos' => [150, "2\u{00A0}h 30\u{00A0}min"],
            'nada' => [0, "0\u{00A0}min"],
        ];
    }

    #[DataProvider('durations')]
    public function test_a_duration_reads_in_hours_and_minutes(int $minutes, string $expected): void
    {
        $this->assertSame($expected, Dates::duration($minutes));
    }
}
