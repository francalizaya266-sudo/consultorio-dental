<?php

namespace App\Filament\Widgets\Reports;

use App\Services\Reports\EarningsReport;
use App\Support\Money;

/** Lo que dejó cada tratamiento en el período (lo realizado). Los más chicos se juntan en "Otros". */
class IncomeByTreatmentChart extends ReportChart
{
    private const SHOWN = 6;

    protected ?string $heading = 'Ingresos por tratamiento';

    protected string $unit = 'money';

    protected bool $horizontal = true;

    protected ?string $maxHeight = '280px';

    protected function chartData(): array
    {
        $rows = collect($this->earnings()->byTreatment());
        $named = $rows->reject(fn (array $row) => $row['name'] === EarningsReport::OTHER_CONCEPTS);
        $shown = $named->take(self::SHOWN);
        $others = $rows->sum('produced') - $shown->sum('produced');

        $pairs = $shown->pluck('produced', 'name')->all() + ($others > 0 ? ['Otros' => round($others, 2)] : []);

        return [
            'labels' => array_keys($pairs),
            'datasets' => [['label' => 'Realizado', 'data' => array_values($pairs)]],
        ];
    }

    protected function finding(): ?string
    {
        $pairs = $this->pairs();
        $top = array_key_first($pairs);

        return $top === 'Otros' ? null : 'Lo que más dejó: '.$top.' ('.Money::format($pairs[$top]).')';
    }
}
