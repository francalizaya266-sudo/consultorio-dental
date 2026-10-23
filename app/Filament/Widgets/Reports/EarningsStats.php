<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Support\StatComparison;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Services\Reports\EarningsReport;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Lo cobrado y lo realizado en el período, comparado con el período anterior. */
class EarningsStats extends StatsOverviewWidget
{
    use ReadsReportFilters;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $report = $this->earnings();
        $previous = $this->earnings($report->period->previous());
        $count = $report->paymentsCount();
        $collected = $report->collected();

        return array_values(array_filter([
            StatComparison::make('Cobrado', $collected, $previous->collected(), $report->period->previous(), Money::format(...))
                ->icon('heroicon-o-banknotes'),
            StatComparison::make('Realizado', $report->produced(), $previous->produced(), $report->period->previous(), Money::format(...))
                ->icon('heroicon-o-check-badge'),
            Stat::make('Pagos', number_format($count))
                ->description($count > 0 ? 'Promedio '.Money::format($collected / $count) : 'Ningún pago en este período')
                ->icon('heroicon-o-receipt-percent'),
            // Lo que se debe no es de un doctor: solo se muestra con todos.
            $report->doctorId === null
                ? Stat::make('Por cobrar (a hoy)', Money::format(EarningsReport::outstanding()))
                    ->description('Saldos pendientes de todos los pacientes')
                    ->icon('heroicon-o-scale')
                : null,
        ]));
    }
}
