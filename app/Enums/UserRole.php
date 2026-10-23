<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

/** Rol del personal. */
enum UserRole: string implements HasLabel, HasColor
{
    case Admin = 'admin';
    case Reception = 'reception';
    case Doctor = 'doctor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Reception => 'Recepción',
            self::Doctor => 'Doctor',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Reception => 'info',
            self::Doctor => 'success',
        };
    }
}
