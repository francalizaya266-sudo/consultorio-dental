<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** De dónde viene una opinión de paciente. */
enum ReviewSource: string implements HasLabel
{
    case Google = 'google';
    case Facebook = 'facebook';
    case Clinic = 'clinic';

    public function getLabel(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Facebook => 'Facebook',
            self::Clinic => 'Encuesta del consultorio',
        };
    }
}
