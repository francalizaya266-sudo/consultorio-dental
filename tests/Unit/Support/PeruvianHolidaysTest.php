<?php

namespace Tests\Unit\Support;

use App\Support\PeruvianHolidays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PeruvianHolidaysTest extends TestCase
{
    public static function holyWeeks(): array
    {
        return [
            'Pascua en abril' => [2026, '2026-04-02', '2026-04-03'],
            'Pascua en marzo' => [2027, '2027-03-25', '2027-03-26'],
            'Viernes Santo a fin de marzo' => [2029, '2029-03-29', '2029-03-30'],
            'la Pascua más tardía' => [2038, '2038-04-22', '2038-04-23'],
        ];
    }

    #[DataProvider('holyWeeks')]
    public function test_holy_thursday_and_good_friday_follow_easter_each_year(int $year, string $thursday, string $friday): void
    {
        $holidays = PeruvianHolidays::of($year);

        $this->assertSame(['Jueves Santo', 'Viernes Santo'], [$holidays[$thursday] ?? null, $holidays[$friday] ?? null]);
    }

    public function test_the_year_lists_the_sixteen_national_holidays_in_date_order(): void
    {
        $holidays = PeruvianHolidays::of(2027);
        $dates = array_keys($holidays);
        $sorted = $dates;
        sort($sorted);

        $this->assertCount(16, $holidays);
        $this->assertSame($sorted, $dates);
        $this->assertSame(
            ['Año Nuevo', 'Fiestas Patrias', 'Fiestas Patrias', 'Navidad'],
            [$holidays['2027-01-01'], $holidays['2027-07-28'], $holidays['2027-07-29'], $holidays['2027-12-25']],
        );
    }
}
