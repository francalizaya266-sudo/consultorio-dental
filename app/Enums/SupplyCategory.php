<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Para ordenar el inventario y el conteo del mes. */
enum SupplyCategory: string implements HasLabel
{
    case Anesthesia = 'anesthesia';
    case Restorative = 'restorative';
    case Endodontics = 'endodontics';
    case Prevention = 'prevention';
    case Surgery = 'surgery';
    case Implants = 'implants';
    case Orthodontics = 'orthodontics';
    case Biosafety = 'biosafety';
    case Laboratory = 'laboratory';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Anesthesia => 'Anestésicos',
            self::Restorative => 'Resinas y restauración',
            self::Endodontics => 'Endodoncia',
            self::Prevention => 'Prevención y limpieza',
            self::Surgery => 'Cirugía',
            self::Implants => 'Implantes',
            self::Orthodontics => 'Ortodoncia',
            self::Biosafety => 'Bioseguridad',
            self::Laboratory => 'Laboratorio',
            self::Other => 'Otros',
        };
    }
}
