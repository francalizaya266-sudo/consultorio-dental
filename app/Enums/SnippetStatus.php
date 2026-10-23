<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

/** Estado de la opción. */
enum SnippetStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case Proposed = 'proposed';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Proposed => 'Propuesta para todos',
            self::Archived => 'Archivada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Proposed => 'warning',
            self::Archived => 'gray',
        };
    }
}
