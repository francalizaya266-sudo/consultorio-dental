<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Fechas y horas como las lee el paciente en la web y en los mensajes. */
final class Dates
{
    /** "Lunes 3 de noviembre". */
    public static function day(CarbonInterface|string $date): string
    {
        return ucfirst(CarbonImmutable::parse($date)->translatedFormat('l j \d\e F'));
    }

    /** "10:30 a. m.", con espacios que no se cortan al final de la línea. */
    public static function time(CarbonInterface|string $time): string
    {
        $time = CarbonImmutable::parse($time);

        return $time->format('g:i')."\u{00A0}".($time->hour < 12 ? "a.\u{00A0}m." : "p.\u{00A0}m.");
    }

    /** "Hoy", "Mañana" o "Lunes 3 de noviembre"; con $withDate, "Hoy, viernes 23 de octubre". */
    public static function relativeDay(CarbonInterface|string $date, bool $withDate = false): string
    {
        $date = CarbonImmutable::parse($date);
        $relative = match (true) {
            $date->isToday() => 'Hoy',
            $date->isTomorrow() => 'Mañana',
            default => null,
        };

        if ($relative === null) {
            return self::day($date);
        }

        return $withDate ? $relative.', '.mb_strtolower(self::day($date)) : $relative;
    }
}
