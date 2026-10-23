<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Uso del enlace. */
enum MagicLinkPurpose: string implements HasLabel
{
    case Appointment = 'appointment';
    case Access = 'access';
    case Verify = 'verify';

    public function getLabel(): string
    {
        return match ($this) {
            self::Appointment => 'Enlace de la cita',
            self::Access => 'Acceso a Mi cuenta',
            self::Verify => 'Verificación de identidad',
        };
    }
}
