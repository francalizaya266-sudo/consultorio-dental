<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

/** Estado del procedimiento. */
enum PlanItemStatus: string implements HasLabel, HasColor
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por hacer',
            self::InProgress => 'En curso',
            self::Done => 'Hecho',
            self::Cancelled => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::InProgress => 'primary',
            self::Done => 'success',
            self::Cancelled => 'gray',
        };
    }
}
