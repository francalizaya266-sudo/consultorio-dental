<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Los feriados nacionales del Perú de un año, para cargarlos de una vez como días en que el consultorio no atiende. */
final class PeruvianHolidays
{
    /** Los de fecha fija: "mes-día" => nombre. */
    private const FIXED = [
        '01-01' => 'Año Nuevo',
        '05-01' => 'Día del Trabajo',
        '06-07' => 'Batalla de Arica y Día de la Bandera',
        '06-29' => 'San Pedro y San Pablo',
        '07-23' => 'Día de la Fuerza Aérea del Perú',
        '07-28' => 'Fiestas Patrias',
        '07-29' => 'Fiestas Patrias',
        '08-06' => 'Batalla de Junín',
        '08-30' => 'Santa Rosa de Lima',
        '10-08' => 'Combate de Angamos',
        '11-01' => 'Día de Todos los Santos',
        '12-08' => 'Inmaculada Concepción',
        '12-09' => 'Batalla de Ayacucho',
        '12-25' => 'Navidad',
    ];

    /**
     * Los feriados del año en orden de fecha, con Jueves y Viernes Santo (que cambian cada año).
     *
     * @return array<string, string> Fecha ("2027-04-02") => nombre.
     */
    public static function of(int $year): array
    {
        $easter = self::easterSunday($year);

        $holidays = collect(self::FIXED)
            ->mapWithKeys(fn (string $name, string $monthDay) => ["{$year}-{$monthDay}" => $name])
            ->put($easter->subDays(3)->toDateString(), 'Jueves Santo')
            ->put($easter->subDays(2)->toDateString(), 'Viernes Santo')
            ->all();

        ksort($holidays);

        return $holidays;
    }

    /** Domingo de Pascua de ese año (cálculo gregoriano de Meeus). */
    private static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = ($h + $l - 7 * $m + 114) % 31 + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
