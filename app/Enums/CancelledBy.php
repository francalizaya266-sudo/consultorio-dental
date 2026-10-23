<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Quién canceló. */
enum CancelledBy: string implements HasLabel
{
    case Patient = 'patient';
    case Clinic = 'clinic';

    public function getLabel(): string
    {
        return match ($this) {
            self::Patient => 'Paciente',
            self::Clinic => 'Consultorio',
        };
    }
}
