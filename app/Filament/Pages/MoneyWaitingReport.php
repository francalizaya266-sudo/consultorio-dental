<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasReportFilters;
use App\Filament\Widgets\Reports\MoneyWaitingLists;
use App\Filament\Widgets\Reports\MoneyWaitingStats;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * El dinero que está esperando una llamada: presupuestos sin respuesta, saldos, cuotas atrasadas, cuántos
 * presupuestos se aceptan y lo que se perdió con las faltas. Lo que espera es a hoy; lo demás, del período.
 */
class MoneyWaitingReport extends BaseDashboard
{
    use HasReportFilters;

    protected static string $routePath = 'dinero-en-espera';

    protected static ?string $title = 'Dinero en espera';

    protected static ?string $navigationParentItem = 'Reportes';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public function getSubheading(): string
    {
        return 'Lo que se puede cobrar con una llamada. Los presupuestos, saldos y cuotas van a hoy; lo aceptado y las faltas, del período.';
    }

    public function getWidgets(): array
    {
        return [MoneyWaitingStats::class, MoneyWaitingLists::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
