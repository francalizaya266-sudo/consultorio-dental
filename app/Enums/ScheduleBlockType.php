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
            // El almuerzo de todos los días va en el horario del doctor: esto es un descanso de una vez.
            self::Lunch => 'Descanso',
            self::Course => 'Curso',
            self::Vacation => 'Vacaciones',
            self::Holiday => 'Feriado',
            self::Other => 'Otro',
        };
    }
}
