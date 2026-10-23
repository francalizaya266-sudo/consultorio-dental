<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReportPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Viernes 23 de octubre de 2026.
        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Lima'));
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} Desde, hasta, etiqueta y etiqueta corta */
    private function describe(ReportPeriod $period): array
    {
        return [$period->from->toDateString(), $period->to->toDateString(), $period->label(), $period->shortLabel()];
    }

    public function test_this_month_runs_until_today_and_compares_with_the_same_days_of_last_month(): void
    {
        $period = ReportPeriod::fromFilters(['period' => 'month']);

        $this->assertSame(['2026-10-01', '2026-10-23', 'Del 1 al 23 de octubre de 2026', '1–23 oct.'], $this->describe($period));
        $this->assertSame(['2026-09-01', '2026-09-23', 'Del 1 al 23 de septiembre de 2026', '1–23 sep.'], $this->describe($period->previous()));
        $this->assertSame('2026-10-01 00:00:00', $period->from->toDateTimeString());
        $this->assertSame('2026-10-23 23:59:59', $period->to->toDateTimeString());
        $this->assertSame(23, $period->days());
        $this->assertFalse($period->groupsByMonth());
        $this->assertSame('2026-10-01_2026-10-23', $period->slug());

        // Sin filtros, también es este mes.
        $this->assertEquals($period, ReportPeriod::fromFilters(null));
    }

    public function test_last_month_is_the_whole_month_against_the_whole_month_before(): void
    {
        $period = ReportPeriod::fromFilters(['period' => 'last_month']);

        $this->assertSame(['2026-09-01', '2026-09-30', 'Septiembre de 2026', 'septiembre'], $this->describe($period));
        $this->assertSame(['2026-08-01', '2026-08-31', 'Agosto de 2026', 'agosto'], $this->describe($period->previous()));
    }

    public function test_today_and_this_week_compare_with_the_same_days_last_week(): void
    {
        $today = ReportPeriod::fromFilters(['period' => 'today']);
        $week = ReportPeriod::fromFilters(['period' => 'week']);

        $this->assertSame(['2026-10-23', '2026-10-23', 'Viernes 23 de octubre de 2026', '23 oct.'], $this->describe($today));
        $this->assertSame(['2026-10-16', '2026-10-16', 'Viernes 16 de octubre de 2026', '16 oct.'], $this->describe($today->previous()));
        $this->assertSame(['2026-10-19', '2026-10-23', 'Del 19 al 23 de octubre de 2026', '19–23 oct.'], $this->describe($week));
        $this->assertSame(['2026-10-12', '2026-10-16'], array_slice($this->describe($week->previous()), 0, 2));
    }

    public function test_this_year_groups_by_month_and_compares_with_the_same_stretch_of_last_year(): void
    {
        $period = ReportPeriod::fromFilters(['period' => 'year']);

        $this->assertSame(['2026-01-01', '2026-10-23', 'Del 1 de enero al 23 de octubre de 2026', '1 ene. – 23 oct.'], $this->describe($period));
        $this->assertSame(['2025-01-01', '2025-10-23', 'Del 1 de enero al 23 de octubre de 2025', '1 ene. – 23 oct. 2025'], $this->describe($period->previous()));
        $this->assertTrue($period->groupsByMonth());

        $whole = new ReportPeriod(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-12-31'));
        $this->assertSame(['Año 2025', '2025'], [$whole->label(), $whole->shortLabel()]);
        $this->assertSame(['2024-01-01', '2024-12-31', 'Año 2024', '2024'], $this->describe($whole->previous()));
    }

    public function test_chosen_dates_are_put_in_order_and_compared_with_the_days_just_before(): void
    {
        $period = ReportPeriod::fromFilters(['period' => 'custom', 'from' => '2026-10-14', 'to' => '2026-10-05']);

        $this->assertSame(['2026-10-05', '2026-10-14', 'Del 5 al 14 de octubre de 2026', '5–14 oct.'], $this->describe($period));
        $this->assertSame(['2026-09-25', '2026-10-04', 'Del 25 de septiembre al 4 de octubre de 2026', '25 sep. – 4 oct.'], $this->describe($period->previous()));

        $acrossYears = ReportPeriod::fromFilters(['period' => 'custom', 'from' => '2025-12-20', 'to' => '2026-01-05']);
        $this->assertSame('Del 20 de diciembre de 2025 al 5 de enero de 2026', $acrossYears->label());
        $this->assertSame('20 dic. 2025 – 5 ene. 2026', $acrossYears->shortLabel());
    }

    public function test_incomplete_or_invalid_dates_fall_back_to_this_month(): void
    {
        foreach ([['from' => '2026-10-05'], ['from' => 'ayer', 'to' => '2026-10-05'], []] as $dates) {
            $period = ReportPeriod::fromFilters(['period' => 'custom', ...$dates]);

            $this->assertSame(['2026-10-01', '2026-10-23'], [$period->from->toDateString(), $period->to->toDateString()]);
        }
    }

    public function test_the_last_months_end_today(): void
    {
        $period = ReportPeriod::months(12);

        $this->assertSame(['2025-11-01', '2026-10-23'], [$period->from->toDateString(), $period->to->toDateString()]);
    }
}
