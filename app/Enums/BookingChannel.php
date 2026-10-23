<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Quién creó la cita. */
enum BookingChannel: string implements HasLabel
{
    case Web = 'web';
    case Reception = 'reception';
    case Doctor = 'doctor';
    case Assistant = 'assistant';

    public function getLabel(): string
    {
        return match ($this) {
            self::Web => 'Web (paciente)',
            self::Reception => 'Recepción',
            self::Doctor => 'Doctor',
            self::Assistant => 'Asistente IA',
        };
    }
}
