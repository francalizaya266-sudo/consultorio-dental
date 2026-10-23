<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

/** Estado en lista de espera. */
enum WaitlistStatus: string implements HasLabel, HasColor
{
    case Waiting = 'waiting';
    case Offered = 'offered';
    case Booked = 'booked';
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Waiting => 'En espera',
            self::Offered => 'Avisado',
            self::Booked => 'Agendado',
            self::Removed => 'Retirado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Waiting => 'warning',
            self::Offered => 'info',
            self::Booked => 'success',
            self::Removed => 'gray',
        };
    }
}
