<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Dentición. */
enum Dentition: string implements HasLabel
{
    case Permanent = 'permanent';
    case Primary = 'primary';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Permanent => 'Adulto (permanente)',
            self::Primary => 'Niño (decidua)',
            self::Mixed => 'Mixta',
        };
    }
}
