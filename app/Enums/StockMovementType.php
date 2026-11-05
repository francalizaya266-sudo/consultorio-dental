<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Por qué cambió lo que hay de un material. */
enum StockMovementType: string implements HasColor, HasLabel
{
    /** Llegó una compra (o se cargó lo que ya había). */
    case Receipt = 'receipt';
    /** Se usó en una atención. */
    case Use = 'use';
    /** Volvió al stock porque se reabrió la atención. */
    case Return = 'return';
    /** Diferencia del conteo del mes. */
    case Adjustment = 'adjustment';
    /** Venció, se dañó o se perdió. */
    case WriteOff = 'write_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::Receipt => 'Entrada',
            self::Use => 'Uso en atención',
            self::Return => 'Devuelto',
            self::Adjustment => 'Ajuste de conteo',
            self::WriteOff => 'Baja',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Receipt => 'success',
            self::Use, self::Return => 'gray',
            self::Adjustment => 'warning',
            self::WriteOff => 'danger',
        };
    }
}
