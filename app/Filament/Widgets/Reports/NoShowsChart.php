<?php

namespace App\Filament\Widgets\Reports;

/** Porcentaje de citas a las que el paciente no vino, mes a mes. */
class NoShowsChart extends ReportChart
{
    protected ?string $heading = 'Inasistencias · últimos 12 meses';

    protected string $unit = 'percent';

    protected function getType(): string
    {
        return 'line';
    }

    protected function chartData(): array
    {
        $months = $this->activity()->noShowRateByMonth(12);

        return [
            'labels' => array_keys($months),
            'datasets' => [['label' => 'No vinieron', 'data' => array_values($months)]],
        ];
    }

    /** Cero inasistencias también es un dato: vacío es solo cuando no hubo citas. */
    public function isEmpty(): bool
    {
        return array_filter($this->values(), fn ($v) => $v !== null) === [];
    }

    protected function finding(): ?string
    {
        $months = $this->values();
        $current = end($months);
        $values = array_filter($months, fn ($v) => $v !== null);

        return ($current === null ? 'Este mes aún sin citas' : 'Este mes: '.$this->formatValue($current))
            .' · promedio: '.$this->formatValue(array_sum($values) / max(1, count($values)));
    }
}
