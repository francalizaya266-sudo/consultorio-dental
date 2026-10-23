<?php

namespace App\Filament\Widgets\Reports;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * Qué parte del horario de atención estuvo reservada: por doctor o, con uno solo (o uno elegido),
 * por día de la semana, para ver qué días tienen espacio.
 */
class OccupancyChart extends ReportChart
{
    protected string $unit = 'percent';

    protected bool $horizontal = true;

    /** @var array<string, int>|null */
    private ?array $byDoctor = null;

    public function getHeading(): string|Htmlable|null
    {
        return $this->comparesDoctors() ? 'Ocupación de la agenda por doctor' : 'Ocupación de la agenda por día';
    }

    protected function chartData(): array
    {
        $values = $this->comparesDoctors() ? $this->byDoctor() : $this->activity()->occupancyByWeekday();

        return [
            'labels' => array_keys($values),
            'datasets' => [['label' => 'Ocupado', 'data' => array_values($values)]],
        ];
    }

    protected function finding(): ?string
    {
        if ($this->comparesDoctors()) {
            $values = $this->values();

            return 'En promedio, '.round(array_sum($values) / count($values)).' % del horario reservado';
        }

        $total = array_values($this->byDoctor())[0] ?? 0;
        $days = $this->pairs();

        return count($days) > 1
            ? "{$total} % del horario reservado · el día más libre: ".Str::lower(array_keys($days, min($days))[0])
            : "{$total} % del horario reservado";
    }

    private function comparesDoctors(): bool
    {
        return count($this->byDoctor()) > 1;
    }

    /** @return array<string, int> */
    private function byDoctor(): array
    {
        return $this->byDoctor ??= $this->activity()->occupancyByDoctor();
    }
}
