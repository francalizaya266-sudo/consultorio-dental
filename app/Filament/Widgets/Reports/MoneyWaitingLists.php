<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Services\Reports\MoneyWaiting;
use Filament\Widgets\Widget;

/** Con nombre: a quién se le presentó un presupuesto y no respondió, quién faltó y qué se perdió, quién debe. */
class MoneyWaitingLists extends Widget
{
    use ReadsReportFilters;

    protected string $view = 'filament.widgets.money-waiting-lists';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $waiting = new MoneyWaiting($this->reportPeriod(), $this->reportDoctorId());
        $acceptance = $waiting->acceptance();

        return [
            'plans' => $waiting->unansweredPlans(),
            'noShows' => $waiting->noShows(),
            'balances' => $waiting->doctorId === null ? $waiting->balances() : null,
            'installments' => $waiting->lateInstallments(),
            'acceptance' => $acceptance,
            'period' => $waiting->period,
        ];
    }
}
