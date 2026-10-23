<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Sexo. */
enum Sex: string implements HasLabel
{
    case Female = 'F';
    case Male = 'M';

    public function getLabel(): string
    {
        return match ($this) {
            self::Female => 'Femenino',
            self::Male => 'Masculino',
        };
    }
}
