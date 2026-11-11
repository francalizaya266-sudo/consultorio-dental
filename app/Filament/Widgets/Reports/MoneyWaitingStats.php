<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Pages\OwingBalances;
use App\Filament\Resources\Installments\InstallmentResource;
use App\Filament\Support\StatComparison;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Services\Reports\MoneyWaiting;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** El dinero que espera, en cinco números: lo que no respondieron, lo que deben, cuánto se acepta y lo que se perdió. */
class MoneyWaitingStats extends StatsOverviewWidget
{
    use ReadsReportFilters;

    protected ?string $pollingInterval = null;

    /**
     * Cinco números van en dos filas sin hueco: tres arriba y dos abajo, más anchos (ver getStats).
     * Con un doctor elegido son cuatro y entran en una sola fila.
     *
     * @return array<string, int>
     */
    protected function getColumns(): array
    {
        return count($this->getCachedStats()) === 5 ? ['lg' => 6] : ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $waiting = $this->waiting();
        $plans = $waiting->unansweredPlans();
        $installments = $waiting->lateInstallments();
        $acceptance = $waiting->acceptance();
        $noShows = $waiting->noShows();
        $balances = $waiting->doctorId === null ? $waiting->balances() : null;

        $stats = array_values(array_filter([
            Stat::make('Presupuestos sin respuesta', Money::format($plans->sum('total')))
                ->description($plans->isEmpty() ? 'Todos respondieron' : $plans->count().($plans->count() === 1 ? ' presupuesto' : ' presupuestos').' · el más antiguo, hace '.$plans->max('waiting_days').' días')
                ->icon('heroicon-o-document-text'),
            Stat::make('Presupuestos aceptados', $acceptance['rate'] === null ? '—' : StatComparison::percent($acceptance['rate']))
                ->description($acceptance['presented'] === 0
                    ? 'Ninguno presentado en el período'
                    : $acceptance['accepted'].' de '.$acceptance['presented'].' presentados · '.Money::format($acceptance['accepted_amount']).' de '.Money::format($acceptance['presented_amount']))
                ->icon('heroicon-o-hand-thumb-up'),
            // Lo que se debe es del paciente, no de un doctor: solo con todos.
            $balances ? Stat::make('Saldos por cobrar', Money::format($balances['amount']))
                ->description($balances['count'] === 0 ? 'Nadie debe' : 'Entre '.$balances['count'].($balances['count'] === 1 ? ' paciente' : ' pacientes'))
                ->icon('heroicon-o-scale')
                ->url(OwingBalances::getUrl()) : null,
            Stat::make('Cuotas atrasadas', Money::format($installments['amount']))
                ->description($installments['count'] === 0 ? 'Ninguna atrasada' : $installments['count'].($installments['count'] === 1 ? ' cuota' : ' cuotas'))
                ->icon('heroicon-o-calendar-days')
                ->url(InstallmentResource::getUrl('index', ['tab' => 'late'])),
            Stat::make('Perdido por faltas', Money::format((float) $noShows->sum('lost')))
                ->description($noShows->isEmpty() ? 'Nadie faltó en el período' : $noShows->count().($noShows->count() === 1 ? ' falta' : ' faltas').' · '.(($rebooked = $noShows->where('rebooked', true)->count()) === 1 ? '1 volvió' : $rebooked.' volvieron').' a agendar')
                ->icon('heroicon-o-user-minus'),
        ]));

        if (count($stats) === 5) {
            foreach ($stats as $position => $stat) {
                $stat->columnSpan(['lg' => $position < 3 ? 2 : 3]);
            }
        }

        return $stats;
    }

    private function waiting(): MoneyWaiting
    {
        return new MoneyWaiting($this->reportPeriod(), $this->reportDoctorId());
    }
}
