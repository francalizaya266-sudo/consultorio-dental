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

/**
 * Ingresos por día, doctor, tratamiento y medio de pago, para imprimir o bajar en Excel. Se llaman
 * ingresos y no ganancias porque no descuentan costos (materiales, laboratorio, sueldos).
 */
class Earnings extends BaseDashboard
{
    use HasReportFilters;

    protected static string $routePath = 'ingresos';

    protected static ?string $title = 'Ingresos';

    protected static ?string $navigationParentItem = 'Reportes';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    public function getSubheading(): string
    {
        return 'Lo atendido y lo cobrado en el período, al detalle. No descuenta costos de materiales, laboratorio ni sueldos.';
    }

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
