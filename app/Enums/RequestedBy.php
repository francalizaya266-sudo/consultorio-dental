<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Solicitado por. */
enum RequestedBy: string implements HasLabel
{
    case Patient = 'patient';
    case Doctor = 'doctor';
    case Reception = 'reception';

    public function getLabel(): string
    {
        return match ($this) {
            self::Patient => 'Paciente',
            self::Doctor => 'Doctor',
            self::Reception => 'Recepción',
        };
    }
}
