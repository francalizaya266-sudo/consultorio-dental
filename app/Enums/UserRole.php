<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Rol del personal. */
enum UserRole: string implements HasColor, HasLabel
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
