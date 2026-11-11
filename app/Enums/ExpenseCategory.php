<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** En qué se gastó. Los materiales no van aquí: el inventario ya cuenta lo que se usa en cada atención. */
enum ExpenseCategory: string implements HasLabel
{
    case Laboratory = 'laboratory';
    case Salaries = 'salaries';
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Maintenance = 'maintenance';
    case Marketing = 'marketing';
    case Taxes = 'taxes';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Laboratory => 'Laboratorio dental',
            self::Salaries => 'Sueldos y honorarios',
            self::Rent => 'Alquiler',
            self::Utilities => 'Luz, agua, internet y teléfono',
            self::Maintenance => 'Mantenimiento y limpieza',
            self::Marketing => 'Publicidad',
            self::Taxes => 'Impuestos y trámites',
            self::Other => 'Otros gastos',
        };
    }
}
