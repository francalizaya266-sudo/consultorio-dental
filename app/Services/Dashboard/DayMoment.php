<?php

namespace App\Services\Dashboard;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Web\ClinicInfo;
use Carbon\CarbonImmutable;

/**
 * En qué momento del día está el consultorio, para que "Hoy" muestre lo que toca: antes de abrir, confirmar
 * las citas; durante el día, la agenda y la sala; al terminar, el cierre. El día va desde lo primero (el
 * horario de los doctores o la primera cita) hasta lo último (el fin del horario o de la última cita).
 */
final class DayMoment
{
    public const BEFORE = 'before';

    public const OPEN = 'open';

    public const CLOSING = 'closing';

    public static function at(CarbonImmutable $now): string
    {
        $ranges = app(ClinicInfo::class)->hours()->forDay($now->dayOfWeekIso);
        $appointments = Appointment::query()
            ->whereBetween('starts_at', [$now->startOfDay(), $now->endOfDay()])
            ->whereIn('status', AppointmentStatus::occupying())
            ->get(['starts_at', 'ends_at']);

        $starts = collect($ranges)->pluck(0)->merge($appointments->map(fn (Appointment $appointment) => $appointment->starts_at->format('H:i')));
        $ends = collect($ranges)->pluck(1)->merge($appointments->map(fn (Appointment $appointment) => $appointment->ends_at->format('H:i')));

        if ($starts->isEmpty()) {
            return self::OPEN;
        }

        $time = $now->format('H:i');

        return match (true) {
            $time < $starts->min() => self::BEFORE,
            $time >= $ends->max() => self::CLOSING,
            default => self::OPEN,
        };
    }
}
