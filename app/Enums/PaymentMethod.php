<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Medio de pago. */
enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Card = 'card';
    case Yape = 'yape';
    case Plin = 'plin';
    case Transfer = 'transfer';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
            self::Yape => 'Yape',
            self::Plin => 'Plin',
            self::Transfer => 'Transferencia',
            self::Other => 'Otro',
        };
    }
}
