<?php

namespace App\Services\Web;

use App\Enums\AppointmentStatus;
use App\Enums\PatientRelationship;
use App\Enums\PlanItemStatus;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Booking\WebAvailability;
use App\Support\FamilyNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La portada para quien vuelve desde su celular recordado: su próxima cita con sus botones, las sesiones que le
 * faltan de su plan (y pedir la próxima) y, si ya le toca, su control. Lo mismo que ve en Mi cuenta sin verificar
 * de nuevo: nada de montos ni pagos, que siguen pidiendo el enlace por WhatsApp.
 */
final class ReturningPatient
{
    /** Citas que se muestran en la portada; el resto está en Mi cuenta. */
    private const APPOINTMENTS = 2;

    public function __construct(private readonly WebAvailability $availability) {}

    /**
     * @return array{
     *     name: string|null,
     *     names: array<int, string>,
     *     guests: list<int>,
     *     appointments: Collection<int, Appointment>,
     *     more: int,
     *     sessions: Collection<int, array{patient: Patient, done: int, total: int, doctor: string|null, requested: bool}>,
     *     checkups: Collection<int, array{patient: Patient, months: int, url: string}>,
     * }
     */
    public function for(Account $account): array
    {
        $patients = $account->patients()->orderByRaw('relationship = ? desc', [PatientRelationship::Self->value])->orderBy('first_name')->get();
        // Quien reservó con este número desde otro celular: se muestra su cita con el aviso, pero no es familia todavía.
        $guests = $account->guestIds();
        $family = $patients->reject(fn (Patient $patient) => in_array($patient->getKey(), $guests, true))->values();

        $upcoming = Appointment::query()
            ->whereIn('patient_id', $patients->modelKeys())
            ->where('starts_at', '>=', now())
            ->whereIn('status', AppointmentStatus::changeable())
            ->with(['patient.medicalBackground', 'doctor', 'treatment'])
            ->oldest('starts_at')
            ->get()
            // La cita que la familia todavía no reconoce va primero: que no quede escondida en "Ver más".
            ->sortBy(fn (Appointment $appointment) => in_array((int) $appointment->patient_id, $guests, true) ? 0 : 1)
            ->values();

        $sessions = $this->sessions($family);

        return [
            // Saluda a quien tiene el celular: el titular. Si solo hay hijos registrados, sin nombre.
            'name' => $family->firstWhere('relationship', PatientRelationship::Self)?->first_name,
            // Cómo se nombra a cada familiar en las tarjetas: con apellido si otro lleva el mismo nombre.
            'names' => FamilyNames::for($patients),
            'guests' => $guests,
            'appointments' => $upcoming->take(self::APPOINTMENTS)->values(),
            'more' => max(0, $upcoming->count() - self::APPOINTMENTS),
            'sessions' => $sessions,
            'checkups' => $this->checkups($family, $sessions->pluck('patient.id')),
        ];
    }

    /** Llave del pedido de "mi próxima sesión": uno por paciente cada 12 horas. */
    public static function sessionRequestKey(Patient $patient): string
    {
        return 'proxima-sesion:'.$patient->getKey();
    }

    /**
     * Quién de la familia aceptó un plan, le faltan sesiones y no tiene cita por delante: cuántas lleva de cuántas.
     *
     * @param  Collection<int, Patient>  $family
     * @return Collection<int, array{patient: Patient, done: int, total: int, doctor: string|null, requested: bool}>
     */
    private function sessions(Collection $family): Collection
    {
        return TreatmentPlan::query()
            ->unscheduled()
            ->whereIn('patient_id', $family->modelKeys())
            ->with(['doctor', 'items'])
            ->latest('accepted_at')
            ->get()
            ->groupBy('patient_id')
            ->map(function (Collection $plans, int $patientId) use ($family) {
                $items = $plans->flatMap->items->filter(fn (TreatmentPlanItem $item) => $item->accepted_at !== null && $item->status !== PlanItemStatus::Cancelled);
                $planned = fn (TreatmentPlanItem $item) => max(1, (int) $item->sessions_planned);
                $patient = $family->firstWhere('id', $patientId);

                return [
                    'patient' => $patient,
                    'done' => (int) $items->sum(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Done ? $planned($item) : min((int) $item->sessions_done, $planned($item))),
                    'total' => (int) $items->sum($planned),
                    'doctor' => $plans->first()->doctor?->name,
                    'requested' => RateLimiter::attempts(self::sessionRequestKey($patient)) > 0,
                ];
            })
            ->filter(fn (array $row) => $row['total'] > $row['done'])
            ->values();
    }

    /**
     * A quién de la familia ya le toca su control (la misma regla que "Llamado a control" en el panel), con el
     * enlace para reservarlo a su nombre. Quien sigue en tratamiento no: a ese lo cita su doctor.
     *
     * @param  Collection<int, Patient>  $family
     * @param  Collection<int, int>  $inTreatment
     * @return Collection<int, array{patient: Patient, months: int, url: string}>
     */
    private function checkups(Collection $family, Collection $inTreatment): Collection
    {
        $evaluation = $this->availability->treatments()->firstWhere('is_evaluation', true);

        return Patient::query()
            ->whereIn('id', $family->modelKeys())
            ->whereNotIn('id', $inTreatment->all())
            ->whereDoesntHave('treatmentPlans', fn (Builder $plans) => $plans->whereIn('status', TreatmentPlan::ACCEPTED_STATUSES))
            ->dueForCheckup()
            ->orderByRaw('relationship = ? desc', [PatientRelationship::Self->value])
            ->orderBy('first_name')
            ->get()
            ->map(fn (Patient $patient) => [
                'patient' => $patient,
                'months' => (int) $patient->last_visit_at->diffInMonths(now()),
                'url' => route('site.booking', array_filter(['tratamiento' => $evaluation?->slug, 'para' => $patient->getKey()])),
            ]);
    }
}
