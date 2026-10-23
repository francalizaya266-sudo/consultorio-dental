<?php

namespace App\Filament\Widgets\Reports;

/** Qué parte del horario de atención de cada doctor estuvo reservada. */
class OccupancyChart extends ReportChart
{
    protected ?string $heading = 'Ocupación de la agenda por doctor';

    protected string $unit = 'percent';

    protected bool $horizontal = true;

    protected function chartData(): array
    {
        $doctors = $this->activity()->occupancyByDoctor();

        return [
            'labels' => array_keys($doctors),
            'datasets' => [['label' => 'Ocupado', 'data' => array_values($doctors)]],
        ];
    }

    protected function finding(): ?string
    {
        $values = $this->values();

        return count($values) > 1
            ? 'En promedio, '.round(array_sum($values) / count($values)).' % del horario reservado'
            : $values[0].' % del horario reservado';
    }
}
