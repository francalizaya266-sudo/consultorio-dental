<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cómo se confirmó. */
enum ConfirmationChannel: string implements HasLabel
{
    case PatientLink = 'patient_link';
    case Reception = 'reception';
    case Doctor = 'doctor';

    public function getLabel(): string
    {
        return match ($this) {
            self::PatientLink => 'Enlace del paciente',
            self::Reception => 'Recepción',
            self::Doctor => 'Doctor',
        };
    }
}
