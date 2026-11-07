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

    /** "lunes 9 de noviembre a las 10:30", para el panel (hora de 24). */
    public static function dayAndTime(CarbonInterface|string $date): string
    {
        return CarbonImmutable::parse($date)->translatedFormat('l j \d\e F \a \l\a\s H:i');
    }

    /** "45 min", "1 h", "2 h 30 min". */
    public static function duration(int $minutes): string
    {
        $hours = intdiv(max(0, $minutes), 60);
        $rest = max(0, $minutes) % 60;

        return match (true) {
            $hours === 0 => "{$rest}\u{00A0}min",
            $rest === 0 => "{$hours}\u{00A0}h",
            default => "{$hours}\u{00A0}h {$rest}\u{00A0}min",
        };
    }
}
