<?php

namespace App\Filament\Widgets\Reports;

use App\Support\Money;

/**
 * Cobrado en cada uno de los últimos 12 meses (no depende del período elegido). El mes en curso va aparte:
 * más claro y fuera del promedio, para no compararlo con meses completos.
 */
class IncomeByMonthChart extends ReportChart
{
    protected ?string $heading = 'Ingresos por mes · últimos 12 meses';

    protected string $unit = 'money';

    protected string $tone = 'money';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '240px';

    protected bool $lastIsInProgress = true;

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
        $today = today();
        $current = ucfirst($today->translatedFormat('F')).' va en '.Money::format(end($values))." (día {$today->day} de {$today->daysInMonth})";
        $complete = array_filter(array_slice($values, 0, -1), fn ($v) => $v > 0);

        return $complete === []
            ? $current
            : $current.' · promedio de los meses completos: '.Money::format(array_sum($complete) / count($complete));
    }
}
