<?php

namespace App\Filament\Widgets\Reports;

/** Pacientes atendidos cada mes: los que vinieron por primera vez y los que volvieron. */
class NewVsReturningChart extends ReportChart
{
    protected ?string $heading = 'Pacientes nuevos y recurrentes · últimos 6 meses';

    protected bool $stacked = true;

    protected array $noun = ['paciente', 'pacientes'];

    protected function chartData(): array
    {
        $months = $this->activity()->newVsReturning(6);

        return [
            'labels' => array_keys($months),
            'datasets' => [
                ['label' => 'Nuevos', 'data' => array_column($months, 'new')],
                ['label' => 'Recurrentes', 'data' => array_column($months, 'returning')],
            ],
        ];
    }

    protected function finding(): ?string
    {
        $new = $this->values(0);
        $returning = $this->values(1);

        return 'Este mes: '.end($new).' '.(end($new) === 1 ? 'nuevo' : 'nuevos').' y '.end($returning).' '.(end($returning) === 1 ? 'recurrente' : 'recurrentes');
    }
}
