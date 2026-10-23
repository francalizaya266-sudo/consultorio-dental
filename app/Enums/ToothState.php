<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

/** Estado en el odontograma. */
enum ToothState: string implements HasLabel, HasColor
{
    case Bad = 'bad';
    case Good = 'good';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bad => 'Por tratar (rojo)',
            self::Good => 'Tratado (azul)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bad => 'danger',
            self::Good => 'info',
        };
    }
}
