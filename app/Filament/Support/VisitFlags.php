<?php

namespace App\Filament\Support;

use App\Enums\RescheduleRequestStatus;
use App\Models\Appointment;
use App\Models\RescheduleRequest;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Patients\PatientInsights;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lo que conviene saber de cada cita antes de que llegue el paciente, para las listas (agenda, Hoy, citas):
 * si tiene una alerta médica (solo para quien ve lo clínico), qué sesión de su plan es, si pidió cambiar el
 * horario y si suele faltar. Todo con unas pocas consultas para la lista entera, no una por cita.
 */
final class VisitFlags
{
    /** Dónde quedan los avisos ya calculados en cada cita de una tabla. */
    private const RELATION = 'visitFlags';

    /**
     * Los avisos de cada cita de la lista.
     *
     * @param  iterable<Appointment>|Paginator  $appointments
     * @return array<int, array{alert: string|null, alertSevere: bool, session: string|null, reschedule: bool, noShows: string|null}> Por id de la cita.
     */
    public static function for(iterable|Paginator $appointments, User $user): array
    {
        $appointments = new Collection($appointments instanceof Paginator ? $appointments->items() : collect($appointments)->all());

        return $appointments->isEmpty() ? [] : self::compute($appointments, $user);
    }

    /**
     * Los avisos de una fila de tabla. La primera fila los calcula para toda la página y los deja en cada cita.
     *
     * @param  iterable<Appointment>|Paginator  $page
     * @return array{alert: string|null, alertSevere: bool, session: string|null, reschedule: bool, noShows: string|null}
     */
    public static function of(Appointment $appointment, User $user, iterable|Paginator $page = []): array
    {
        if (! $appointment->relationLoaded(self::RELATION)) {
            $page = new Collection($page instanceof Paginator ? $page->items() : collect($page)->all());
            $page = $page->contains($appointment) ? $page : new Collection([$appointment]);

            foreach (self::compute($page, $user) as $id => $flags) {
                $page->find($id)?->setRelation(self::RELATION, $flags);
            }

            // Si la fila no es la misma instancia de la página, se calcula sola.
            if (! $appointment->relationLoaded(self::RELATION)) {
                $appointment->setRelation(self::RELATION, self::compute(new Collection([$appointment]), $user)[$appointment->getKey()]);
            }
        }

        return $appointment->getRelation(self::RELATION);
    }

    /** "Sesión 2 de 3", si el procedimiento del plan lleva varias sesiones. */
    public static function session(?TreatmentPlanItem $item): ?string
    {
        return $item && $item->sessions_planned > 1
            ? 'Sesión '.min($item->sessions_done + 1, $item->sessions_planned).' de '.$item->sessions_planned
            : null;
    }

    /**
     * Los avisos como etiquetas cortas, en orden de importancia.
     *
     * @param  array{alert: string|null, alertSevere: bool, session: string|null, reschedule: bool, noShows: string|null}|null  $flags
     * @return list<string>
     */
    public static function labels(?array $flags): array
    {
        return $flags === null ? [] : array_values(array_filter([
            $flags['alert'] !== null ? 'Alerta médica' : null,
            $flags['reschedule'] ? 'Pidió cambio' : null,
            $flags['noShows'],
            $flags['session'],
        ]));
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, array{alert: string|null, alertSevere: bool, session: string|null, reschedule: bool, noShows: string|null}>
     */
    private static function compute(Collection $appointments, User $user): array
    {
        $clinical = $user->canSeeClinical();
        $appointments->loadMissing($clinical ? ['planItem', 'patient.medicalBackground'] : ['planItem', 'patient']);

        $asked = RescheduleRequest::query()
            ->whereIn('appointment_id', $appointments->modelKeys())
            ->where('status', RescheduleRequestStatus::Pending)
            ->pluck('appointment_id')
            ->flip();
        $noShows = PatientInsights::noShowsFor($appointments->pluck('patient_id'));

        return $appointments->mapWithKeys(function (Appointment $appointment) use ($clinical, $asked, $noShows) {
            $item = $appointment->planItem;
            $alerts = $clinical ? ClinicalAlerts::withSeverity($appointment->patient) : [];
            $missed = $noShows[$appointment->patient_id] ?? null;

            return [$appointment->getKey() => [
                'alert' => $alerts === [] ? null : implode(' · ', array_column($alerts, 'text')),
                // Rojo solo si algo puede hacer daño en el sillón (alergia, anticoagulantes…); si no, ámbar.
                'alertSevere' => in_array(true, array_column($alerts, 'severe'), true),
                'session' => self::session($item),
                'reschedule' => $asked->has($appointment->getKey()),
                'noShows' => $missed && $missed['missed'] >= PatientInsights::NO_SHOWS_TO_CALL ? 'Faltó '.$missed['missed'].' de '.$missed['of'] : null,
            ]];
        })->all();
    }
}
