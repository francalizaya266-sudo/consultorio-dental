<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasReportFilters;
use App\Filament\Widgets\Reports\BusiestHoursChart;
use App\Filament\Widgets\Reports\IncomeByMonthChart;
use App\Filament\Widgets\Reports\IncomeByTreatmentChart;
use App\Filament\Widgets\Reports\NewVsReturningChart;
use App\Filament\Widgets\Reports\NoShowsChart;
use App\Filament\Widgets\Reports\OccupancyChart;
use App\Filament\Widgets\Reports\OriginsChart;
use App\Filament\Widgets\Reports\SummaryStats;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/** Cómo va el consultorio: el período en números y los gráficos que piden las decisiones. */
class Summary extends BaseDashboard
{
    use HasReportFilters;

    protected static string $routePath = 'resumen';

    protected static ?string $title = 'Resumen';

    protected static ?string $navigationLabel = 'Reportes';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public function getWidgets(): array
    {
        return [
            SummaryStats::class,
            IncomeByMonthChart::class,
            IncomeByTreatmentChart::class,
            BusiestHoursChart::class,
            NewVsReturningChart::class,
            OriginsChart::class,
            OccupancyChart::class,
            NoShowsChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['md' => 2];
    }
}
