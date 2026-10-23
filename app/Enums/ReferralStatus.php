<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

/** Estado del referido. */
enum ReferralStatus: string implements HasLabel, HasColor
{
    case Pending = 'pending';
    case Qualified = 'qualified';
    case Redeemed = 'redeemed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por asistir',
            self::Qualified => 'Beneficio ganado',
            self::Redeemed => 'Beneficio usado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Qualified => 'success',
            self::Redeemed => 'gray',
        };
    }
}
