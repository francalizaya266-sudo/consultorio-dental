<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Estado de la cita. */
enum AppointmentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Confirmed => 'Confirmada',
            self::CheckedIn => 'En sala',
            self::InProgress => 'En atención',
            self::Completed => 'Atendida',
            self::NoShow => 'No asistió',
            self::Cancelled => 'Cancelada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::CheckedIn => 'info',
            self::InProgress => 'primary',
            self::Completed => 'gray',
            self::NoShow => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** @return list<self> Estados que ocupan el horario en la agenda. */
    public static function occupying(): array
    {
        return [self::Pending, self::Confirmed, self::CheckedIn, self::InProgress, self::Completed];
    }

    /** @return list<self> Estados en los que la cita aún puede moverse o cancelarse. */
    public static function changeable(): array
    {
        return [self::Pending, self::Confirmed];
    }

    public function isChangeable(): bool
    {
        return in_array($this, self::changeable(), true);
    }
}
