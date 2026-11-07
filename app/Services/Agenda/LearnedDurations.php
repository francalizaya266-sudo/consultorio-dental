<?php

namespace App\Services\Agenda;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;

/**
 * Lo que cada doctor realmente tarda en cada tratamiento, medido con la hora real de inicio y fin de sus
 * últimas atenciones. La agenda lo reserva en vez de la duración general cuando hay suficientes atenciones
 * y la diferencia se nota; una duración propia puesta a mano siempre manda.
 */
final class LearnedDurations
{
    /** Atenciones medidas que hacen falta para confiar en el número. */
    public const MIN_SAMPLES = 5;

    /** Se miran las últimas atenciones: así sigue el ritmo actual del doctor. */
    public const SAMPLE_SIZE = 20;

    /** Diferencia con la duración general a partir de la cual vale la pena cambiar lo que se reserva. */
    public const MIN_DIFFERENCE = 10;

    /** Recalcula lo aprendido de cada tratamiento del doctor. */
    public function refresh(Doctor $doctor): void
    {
        foreach ($doctor->treatments as $treatment) {
            $measured = $this->measure($doctor, $treatment);

            $doctor->treatments()->updateExistingPivot($treatment->getKey(), [
                'learned_minutes' => $measured['minutes'] ?? null,
                'learned_samples' => $measured['samples'] ?? null,
            ]);
        }
    }

    /**
     * La mediana de lo que duraron sus últimas atenciones de ese tratamiento, redondeada hacia arriba a 5 minutos.
     * Se descartan las que no se midieron (cerradas otro día) y las que no pueden ser reales (menos de 5 minutos o
     * más de 4 horas). Null si todavía hay pocas.
     *
     * @return array{minutes: int, samples: int}|null
     */
    public function measure(Doctor $doctor, Treatment $treatment): ?array
    {
        $minutes = Appointment::query()
            ->whereBelongsTo($doctor)
            ->whereBelongsTo($treatment)
            ->where('status', AppointmentStatus::Completed)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->whereColumn('finished_at', '!=', 'ends_at')
            ->where('starts_at', '>=', now()->subYear())
            ->latest('starts_at')
            ->get(['started_at', 'finished_at'])
            ->map(fn (Appointment $appointment) => (int) round($appointment->started_at->diffInMinutes($appointment->finished_at, true)))
            ->filter(fn (int $minutes) => $minutes >= 5 && $minutes <= 240)
            ->take(self::SAMPLE_SIZE)
            ->sort()
            ->values();

        if ($minutes->count() < self::MIN_SAMPLES) {
            return null;
        }

        return ['minutes' => (int) (ceil($minutes->median() / 5) * 5), 'samples' => $minutes->count()];
    }

    /**
     * ¿Se reserva lo aprendido en vez de la duración general? Solo si se nota la diferencia y no es un número
     * raro (entre la mitad y el doble de la general).
     */
    public static function applies(?int $learned, int $general): bool
    {
        return $learned !== null
            && abs($learned - $general) >= self::MIN_DIFFERENCE
            && $learned >= $general / 2
            && $learned <= $general * 2;
    }
}
