<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Motivo del bloqueo. */
enum ScheduleBlockType: string implements HasLabel
{
    case Lunch = 'lunch';
    case Course = 'course';
    case Vacation = 'vacation';
    case Holiday = 'holiday';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lunch => 'Almuerzo',
            self::Course => 'Curso',
            self::Vacation => 'Vacaciones',
            self::Holiday => 'Feriado',
            self::Other => 'Otro',
        };
    }
}
