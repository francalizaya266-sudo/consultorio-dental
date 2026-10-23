<?php

namespace App\Filament\Widgets\Reports;

use App\Support\Money;

/** Cobrado día por día (o mes por mes en períodos largos), con los días sin cobros en cero. */
class EarningsChart extends ReportChart
{
    protected string $unit = 'money';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '240px';

    public function getHeading(): string
    {
        return $this->reportPeriod()->groupsByMonth() ? 'Cobrado por mes' : 'Cobrado por día';
    }

    protected function chartData(): array
    {
        $byMonth = $this->reportPeriod()->groupsByMonth();
        $rows = $this->earnings()->byPeriod(withEmpty: true);

        return [
            'labels' => array_map(fn (array $row) => $row['start']->translatedFormat($byMonth ? 'M Y' : 'D j'), $rows),
            'datasets' => [['label' => 'Cobrado', 'data' => array_column($rows, 'collected')]],
        ];
    }

    protected function finding(): ?string
    {
        $rows = collect($this->earnings()->byPeriod())->sortByDesc('collected');
        $best = $rows->first();

        if (! $best || $best['collected'] <= 0) {
            return null;
        }

        return ($this->reportPeriod()->groupsByMonth() ? 'El mejor mes: ' : 'El mejor día: ').$best['label'].' ('.Money::format($best['collected']).')';
    }
}
