<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de documento. */
enum DocumentType: string implements HasLabel
{
    case Dni = 'dni';
    case ForeignerCard = 'ce';
    case Passport = 'passport';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dni => 'DNI',
            self::ForeignerCard => 'Carné de extranjería',
            self::Passport => 'Pasaporte',
            self::Other => 'Otro',
        };
    }
}
