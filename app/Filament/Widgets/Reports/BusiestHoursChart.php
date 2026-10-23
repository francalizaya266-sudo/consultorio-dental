<?php

namespace App\Filament\Widgets\Reports;

use App\Support\Dates;

/** A qué hora empiezan las citas: dónde se llena la agenda y dónde sobra espacio. */
class BusiestHoursChart extends ReportChart
{
    protected ?string $heading = 'Horarios más llenos';

    protected function chartData(): array
    {
        $hours = $this->activity()->busiestHours();

        return [
            'labels' => array_map(fn (int $hour) => self::hour($hour), array_keys($hours)),
            'datasets' => [['label' => 'Citas', 'data' => array_values($hours)]],
        ];
    }

    protected function finding(): ?string
    {
        $pairs = $this->pairs();
        arsort($pairs);

        return 'La hora más pedida: '.array_key_first($pairs);
    }

    /** "9 a. m.", "1 p. m.". */
    private static function hour(int $hour): string
    {
        return str_replace(':00', '', Dates::time(today()->setTime($hour, 0)));
    }
}
