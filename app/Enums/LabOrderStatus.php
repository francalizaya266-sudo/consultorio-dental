<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** En qué va un trabajo de laboratorio. Sale de sus fechas: no se guarda. */
enum LabOrderStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Late = 'late';
    case Received = 'received';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'En el laboratorio',
            self::Late => 'Atrasado',
            self::Received => 'Ya llegó',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Late => 'danger',
            self::Received => 'success',
        };
    }
}
