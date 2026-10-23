<?php

namespace App\Services\Booking;

/** Cómo puede el paciente cambiar el horario de su cita. */
enum RescheduleMode: string
{
    // Nivel 1: faltan más de 24 h, mismo doctor y tratamiento → el cambio se hace solo.
    case Automatic = 'automatic';
    // Nivel 2: propone hasta 3 horarios y recepción o el doctor aprueba uno.
    case NeedsApproval = 'needs_approval';
}
