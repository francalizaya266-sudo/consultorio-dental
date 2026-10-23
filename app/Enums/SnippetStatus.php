<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Estado de la opción. */
enum SnippetStatus: string implements HasColor, HasLabel
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
