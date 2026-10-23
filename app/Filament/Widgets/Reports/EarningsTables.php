<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Services\Reports\EarningsTables as Tables;
use Filament\Widgets\Widget;

/** Las tablas por día, doctor, tratamiento y medio de pago (las mismas que salen al imprimir y en Excel). */
class EarningsTables extends Widget
{
    use ReadsReportFilters;

    protected string $view = 'filament.widgets.earnings-tables';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['tables' => Tables::for($this->earnings())];
    }
}
