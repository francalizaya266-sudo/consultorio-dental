<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/** Parte del día que le acomoda al paciente para venir. */
enum DayPeriod: string implements HasLabel
{
    case Any = 'any';
    case Morning = 'morning';
    case Afternoon = 'afternoon';

    /** Desde esta hora ya es tarde (los turnos de la mañana cierran a la 1 p. m.). */
    public const AFTERNOON_FROM = '13:00';

    public function getLabel(): string
    {
        return match ($this) {
            self::Any => 'A cualquier hora',
            self::Morning => 'En la mañana',
            self::Afternoon => 'En la tarde',
        };
    }

    /** ¿Una cita que empieza a esta hora le acomoda? */
    public function includes(CarbonInterface $startsAt): bool
    {
        $isAfternoon = $startsAt->format('H:i') >= self::AFTERNOON_FROM;

        return match ($this) {
            self::Any => true,
            self::Morning => ! $isAfternoon,
            self::Afternoon => $isAfternoon,
        };
    }
}
