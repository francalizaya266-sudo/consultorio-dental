<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de descuento. */
enum DiscountType: string implements HasLabel
{
    case None = 'none';
    case Percent = 'percent';
    case Amount = 'amount';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Sin descuento',
            self::Percent => 'Porcentaje',
            self::Amount => 'Monto fijo (S/)',
        };
    }
}
