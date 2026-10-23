<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Parentesco con el titular del celular. */
enum PatientRelationship: string implements HasLabel
{
    case Self = 'self';
    case Child = 'child';
    case Parent = 'parent';
    case Spouse = 'spouse';
    case Sibling = 'sibling';
    case Grandparent = 'grandparent';
    case Grandchild = 'grandchild';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Self => 'Titular',
            self::Child => 'Hijo(a)',
            self::Parent => 'Padre / madre',
            self::Spouse => 'Esposo(a) / pareja',
            self::Sibling => 'Hermano(a)',
            self::Grandparent => 'Abuelo(a)',
            self::Grandchild => 'Nieto(a)',
            self::Other => 'Otro',
        };
    }
}
