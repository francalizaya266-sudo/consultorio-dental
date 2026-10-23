<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/** Cómo paga el paciente el plan que aceptó. */
enum PlanPaymentMode: string implements HasDescription, HasLabel
{
    case PerSession = 'per_session';
    case Upfront = 'upfront';
    case Installments = 'installments';

    public function getLabel(): string
    {
        return match ($this) {
            self::PerSession => 'Por sesión',
            self::Upfront => 'Pago único',
            self::Installments => 'En cuotas',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PerSession => 'Paga lo de cada cita al terminarla.',
            self::Upfront => 'Paga todo hoy; las sesiones ya quedan pagadas.',
            self::Installments => 'Una inicial y una cuota al mes; las sesiones no se cobran aparte.',
        };
    }

    /** Lo aceptado se paga con el cronograma, no sesión por sesión. */
    public function hasSchedule(): bool
    {
        return $this !== self::PerSession;
    }
}
