<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Estado del plan. */
enum TreatmentPlanStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Proposed => 'Presentado al paciente',
            self::Accepted => 'Aceptado',
            self::InProgress => 'En tratamiento',
            self::Completed => 'Terminado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Proposed => 'warning',
            self::Accepted => 'info',
            self::InProgress => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
