<?php

namespace App\Services\Patients;

use App\Enums\AppointmentStatus;
use App\Enums\DayPeriod;
use App\Enums\TreatmentKind;
use App\Enums\TreatmentPlanStatus;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanInstallment;
use App\Models\TreatmentPlanItem;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ConsentService;
use App\Services\Clinical\TreatmentPlanService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el sistema deduce de cada paciente con lo que ya guarda: qué quedó pendiente, si atrasó una cuota,
 * si suele faltar, cuándo suele venir y cuándo le toca control. Lo leen la ficha, Hoy, la agenda y el cobro,
 * así todas las pantallas dicen lo mismo.
 */
class PatientInsights
{
    /** Citas pasadas que se miran para contar las faltas. */
    public const RECENT_VISITS = 5;

    /** Desde cuántas faltas en esas citas conviene confirmar por llamada. */
    public const NO_SHOWS_TO_CALL = 2;

    /** Visitas atendidas que se miran para saber cuándo suele venir, y cuántas hacen falta para decirlo. */
    private const HABIT_VISITS = 8;

    private const HABIT_MIN_VISITS = 3;

    /** Los días en plural, como se dicen: "los martes". */
    private const WEEKDAYS = [0 => 'domingos', 1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábados'];

    public function __construct(
        private TreatmentPlanService $plans,
        private BillingService $billing,
        private ConsentService $consents,
    ) {}

    /** La atención que quedó sin terminar (la más reciente). */
    public function openConsultation(Patient $patient): ?Consultation
    {
        return $patient->consultations()->whereNull('closed_at')->with('appointment.treatment')->latest()->first();
    }

    /** La cita de hoy cuyo tratamiento pide consentimiento y todavía no lo firmó. */
    public function visitMissingConsent(Patient $patient): ?Appointment
    {
        return $patient->appointments()
            ->with('treatment')
            ->whereBetween('starts_at', [today(), today()->endOfDay()])
            ->whereIn('status', AppointmentStatus::open())
            ->orderBy('starts_at')
            ->get()
            ->first(fn (Appointment $appointment) => $this->consents->signedForVisit($appointment) === false);
    }

    /** ¿Tiene una cita por delante (desde ahora)? Quien ya se atendió hoy no la tiene: hay que darle la siguiente. */
    public function hasUpcomingVisit(Patient $patient): bool
    {
        return $patient->appointments()
            ->where('starts_at', '>=', now())
            ->whereIn('status', AppointmentStatus::changeable())
            ->exists();
    }

    /** Lo siguiente de su plan aceptado que falta agendar, si no tiene ninguna cita por delante. */
    public function itemToSchedule(Patient $patient): ?TreatmentPlanItem
    {
        return $this->hasUpcomingVisit($patient) ? null : $this->plans->nextToSchedule($patient);
    }

    /** El presupuesto que se le presentó hace una semana o más y no respondió. */
    public function planAwaitingAnswer(Patient $patient): ?TreatmentPlan
    {
        return $patient->treatmentPlans()->awaitingAnswer()->with('items', 'doctor')->oldest('proposed_at')->first();
    }

    /**
     * Su control según lo último que se le hizo: ortodoncia cada mes, implante al año, lo demás a los 6 meses.
     * Null si aún no viene.
     *
     * @return array{kind: TreatmentKind, due: bool, on: CarbonInterface}|null
     */
    public function checkup(Patient $patient): ?array
    {
        if ($patient->last_visit_at === null) {
            return null;
        }

        $last = $patient->appointments()
            ->with('treatment')
            ->where('status', AppointmentStatus::Completed)
            ->latest('starts_at')
            ->first();
        $kind = TreatmentKind::for($last?->treatment);
        $on = $patient->last_visit_at->copy()->addMonths($kind->checkupMonths());

        return ['kind' => $kind, 'due' => $on->lte(now()), 'on' => $on];
    }

    /**
     * Las cuotas de sus planes que ya vencieron sin pagar: cuánto suman y desde cuándo.
     *
     * @return array{due: float, since: CarbonInterface}|null
     */
    public function lateInstallments(Patient $patient): ?array
    {
        $late = $this->installments($patient)->where('state', 'late');

        return $late->isEmpty() ? null : [
            'due' => round($late->sum('due'), 2),
            'since' => $late->min(fn (array $row) => $row['installment']->due_on),
        ];
    }

    /**
     * La próxima cuota por pagar que vence hasta esa fecha (hoy o en los próximos días).
     *
     * @return array{due: float, on: CarbonInterface}|null
     */
    public function installmentDueBy(Patient $patient, CarbonInterface $until): ?array
    {
        $next = $this->installments($patient)
            ->whereIn('state', ['due', 'upcoming'])
            ->filter(fn (array $row) => $row['installment']->due_on->lte($until))
            ->sortBy(fn (array $row) => $row['installment']->due_on)
            ->first();

        return $next ? ['due' => $next['due'], 'on' => $next['installment']->due_on] : null;
    }

    /**
     * Cuántas veces faltó en sus últimas citas pasadas (sin contar las canceladas).
     *
     * @return array{missed: int, of: int}
     */
    public function noShows(Patient $patient): array
    {
        return self::noShowsFor([$patient->getKey()])[$patient->getKey()] ?? ['missed' => 0, 'of' => 0];
    }

    /**
     * Lo mismo para varios pacientes en una sola consulta (agenda, Hoy).
     *
     * @param  iterable<int>  $patientIds
     * @return array<int, array{missed: int, of: int}> Id del paciente => faltas en sus últimas citas
     */
    public static function noShowsFor(iterable $patientIds): array
    {
        $ids = collect($patientIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $recent = Appointment::query()
            ->select('patient_id', 'status')
            ->selectRaw('row_number() over (partition by patient_id order by starts_at desc) as recency')
            ->whereIn('patient_id', $ids->all())
            ->where('starts_at', '<', now())
            ->whereNot('status', AppointmentStatus::Cancelled);

        return DB::query()
            ->fromSub($recent, 'recent')
            ->where('recency', '<=', self::RECENT_VISITS)
            ->groupBy('patient_id')
            ->selectRaw('patient_id, count(*) as visits, sum(case when status = ? then 1 else 0 end) as missed', [AppointmentStatus::NoShow->value])
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->patient_id => ['missed' => (int) $row->missed, 'of' => (int) $row->visits]])
            ->all();
    }

    /**
     * "Faltó a 2 de sus últimas 5 citas", solo si ya son las faltas para llamar.
     *
     * @param  array{missed: int, of: int}  $noShows
     */
    public static function noShowsLabel(array $noShows): ?string
    {
        return $noShows['missed'] >= self::NO_SHOWS_TO_CALL
            ? 'Faltó a '.$noShows['missed'].' de sus últimas '.$noShows['of'].' citas'
            : null;
    }

    /**
     * Cuándo suele venir, si lo hace casi siempre igual: el día que más se repite (la mitad de sus visitas o
     * más) y la parte del día (dos de cada tres o más).
     *
     * @return array{weekday: int|null, period: DayPeriod|null, label: string}|null
     */
    public function usualVisit(Patient $patient): ?array
    {
        $visits = $patient->appointments()
            ->where('status', AppointmentStatus::Completed)
            ->latest('starts_at')
            ->limit(self::HABIT_VISITS)
            ->pluck('starts_at');

        if ($visits->count() < self::HABIT_MIN_VISITS) {
            return null;
        }

        [$weekday, $times] = $visits->countBy(fn (CarbonInterface $start) => $start->dayOfWeek)->sortDesc()->map(fn ($n, $day) => [$day, $n])->first();
        $weekday = $times >= self::HABIT_MIN_VISITS && $times * 2 >= $visits->count() ? $weekday : null;

        $mornings = $visits->filter(fn (CarbonInterface $start) => DayPeriod::Morning->includes($start))->count();
        $period = match (true) {
            $mornings * 3 >= $visits->count() * 2 => DayPeriod::Morning,
            ($visits->count() - $mornings) * 3 >= $visits->count() * 2 => DayPeriod::Afternoon,
            default => null,
        };

        if ($weekday === null && $period === null) {
            return null;
        }

        $label = 'Suele venir '.collect([
            $weekday !== null ? 'los '.self::WEEKDAYS[$weekday] : null,
            $period ? mb_strtolower($period->getLabel()) : null,
        ])->filter()->implode(' ');

        return ['weekday' => $weekday, 'period' => $period, 'label' => $label];
    }

    /**
     * Los pagos del cronograma de sus planes vigentes, con su estado.
     *
     * @return Collection<int, array{installment: TreatmentPlanInstallment, state: string, due: float}>
     */
    private function installments(Patient $patient): Collection
    {
        return $patient->treatmentPlans()
            ->whereNot('status', TreatmentPlanStatus::Cancelled)
            ->whereHas('installments')
            ->with('patient')
            ->get()
            ->flatMap(fn (TreatmentPlan $plan) => $this->billing->installmentStates($plan));
    }
}
