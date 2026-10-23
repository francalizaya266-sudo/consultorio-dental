<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Support\StatComparison;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;

/** El período en cuatro números, cada uno comparado con el período anterior. */
class SummaryStats extends StatsOverviewWidget
{
    use ReadsReportFilters;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $activity = $this->activity();
        $previousPeriod = $activity->period->previous();
        $before = $this->activity($previousPeriod);
        $count = fn (float $n) => number_format($n);

        return [
            StatComparison::make('Citas atendidas', $activity->attended(), $before->attended(), $previousPeriod, $count)
                ->icon('heroicon-o-check-badge'),
            StatComparison::make('Pacientes nuevos', $activity->newPatients(), $before->newPatients(), $previousPeriod, $count)
                ->icon('heroicon-o-user-plus'),
            StatComparison::make('Ingresos (cobrado)', $this->earnings()->collected(), $this->earnings($previousPeriod)->collected(), $previousPeriod, Money::format(...))
                ->icon('heroicon-o-banknotes'),
            StatComparison::points('Inasistencias', $activity->noShowRate(), $before->noShowRate(), $previousPeriod, higherIsBetter: false)
                ->icon('heroicon-o-user-minus'),
        ];
    }
}
