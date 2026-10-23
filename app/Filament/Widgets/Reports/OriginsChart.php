<?php

namespace App\Filament\Widgets\Reports;

use App\Services\Reports\ActivityReport;

/** Cómo llegaron los pacientes de las citas del período (Google, Instagram, recomendación…). */
class OriginsChart extends ReportChart
{
    protected ?string $heading = 'Origen de las citas';

    protected bool $horizontal = true;

    protected ?string $maxHeight = '280px';

    protected function chartData(): array
    {
        $origins = $this->activity()->origins();

        return [
            'labels' => array_keys($origins),
            'datasets' => [['label' => 'Citas', 'data' => array_values($origins)]],
        ];
    }

    protected function finding(): ?string
    {
        $pairs = $this->pairs();
        $total = array_sum($pairs);
        $known = array_diff_key($pairs, [ActivityReport::NO_ORIGIN => true]);

        if ($known === [] || $total === 0) {
            return 'Ninguna cita tiene registrado cómo llegó el paciente.';
        }

        $top = array_key_first($known);

        return 'Llegan más por '.$top.': '.round($known[$top] / $total * 100).' % de las citas';
    }
}
