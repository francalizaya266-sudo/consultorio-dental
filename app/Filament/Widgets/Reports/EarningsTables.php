<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Services\Reports\EarningsTables as Tables;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * Las tablas por día, doctor, tratamiento y medio de pago (las mismas que salen al imprimir y en Excel).
 * Sobre la de los días, cuál fue el mejor: lo que antes decía el gráfico que repetía esa tabla.
 */
class EarningsTables extends Widget
{
    use ReadsReportFilters;

    protected string $view = 'filament.widgets.earnings-tables';

    protected int|string|array $columnSpan = 'full';

    /** @return array{tables: list<array<string, mixed>>, best: string|null} */
    protected function getViewData(): array
    {
        return ['tables' => Tables::for($this->earnings()), 'best' => $this->best()];
    }

    private function best(): ?string
    {
        $best = collect($this->earnings()->byPeriod())->sortByDesc('collected')->first();

        if (! $best || $best['collected'] <= 0) {
            return null;
        }

        return ($this->reportPeriod()->groupsByMonth() ? 'El mejor mes: ' : 'El mejor día: ').$best['label'].' ('.Money::format($best['collected']).')';
    }
}
