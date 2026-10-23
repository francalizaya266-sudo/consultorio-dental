<?php

namespace App\Filament\Widgets\Reports;

use App\Support\Money;

/** Cobrado en cada uno de los últimos 12 meses (no depende del período elegido). */
class IncomeByMonthChart extends ReportChart
{
    protected ?string $heading = 'Ingresos por mes · últimos 12 meses';

    protected string $unit = 'money';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '240px';

    protected function chartData(): array
    {
        $months = $this->activity()->incomeByMonth(12);

        return [
            'labels' => array_keys($months),
            'datasets' => [['label' => 'Cobrado', 'data' => array_values($months)]],
        ];
    }

    protected function finding(): ?string
    {
        $values = $this->values();
        $withIncome = array_filter($values, fn ($v) => $v > 0);
        $average = $withIncome === [] ? 0 : array_sum($withIncome) / count($withIncome);

        return 'Este mes: '.Money::format(end($values)).' · promedio por mes: '.Money::format($average);
    }
}
