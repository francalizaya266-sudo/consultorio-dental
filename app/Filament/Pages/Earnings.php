<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasReportFilters;
use App\Filament\Widgets\Reports\EarningsChart;
use App\Filament\Widgets\Reports\EarningsStats;
use App\Filament\Widgets\Reports\EarningsTables;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Ganancias por día, doctor, tratamiento y medio de pago, para imprimir o bajar en Excel. */
class Earnings extends BaseDashboard
{
    use HasReportFilters;

    protected static string $routePath = 'ganancias';

    protected static ?string $title = 'Ganancias';

    protected static string|UnitEnum|null $navigationGroup = 'Caja';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    public function getWidgets(): array
    {
        return [EarningsStats::class, EarningsChart::class, EarningsTables::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Descargar Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn () => Filament::getPanel('admin')->route('reports.earnings.excel', $this->reportQuery())),
            Action::make('print')
                ->label('Imprimir o PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => Filament::getPanel('admin')->route('reports.earnings.print', $this->reportQuery()), shouldOpenInNewTab: true),
        ];
    }
}
