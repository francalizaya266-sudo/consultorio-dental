<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de odontograma. */
enum OdontogramType: string implements HasLabel
{
    case Initial = 'initial';
    case Evolution = 'evolution';

    public function getLabel(): string
    {
        return match ($this) {
            self::Initial => 'Inicial',
            self::Evolution => 'Evolución',
        };
    }
}
