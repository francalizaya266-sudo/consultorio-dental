<?php

namespace App\Services\Dashboard;

use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\TreatmentKind;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\WhatsappMessage;
use App\Services\Billing\BillingService;
use App\Services\Patients\PatientInsights;
use App\Services\Web\ClinicInfo;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Lo que recepción tiene que perseguir hoy: presupuestos sin respuesta, planes sin próxima cita, quien faltó
 * o canceló, cuotas atrasadas, pacientes para control y cumpleaños. Cuenta con las mismas consultas que las
 * pestañas de Pacientes y Cuotas, así el número del inicio y el de la lista coinciden.
 */
final class FollowUps
{
    /** Pacientes que se muestran por grupo; el resto está en su lista. */
    public const LIMIT = 5;

    /** Los avisos con los que se escribe a cada grupo, y cuántos días un aviso enviado cuenta como "ya le escribiste". */
    public const CONTACT_DAYS = [
        MessageTemplateKey::PlanFollowUp->value => TreatmentPlan::ANSWER_WAIT_DAYS,
        MessageTemplateKey::PlanNextSession->value => 7,
        MessageTemplateKey::MissedVisit->value => 14,
        MessageTemplateKey::CheckupRecall->value => 30,
        MessageTemplateKey::Birthday->value => 0,
        MessageTemplateKey::ReviewRequest->value => 180,
    ];

    public function __construct(
        private readonly BillingService $billing,
        private readonly WhatsAppOutbox $outbox,
    ) {}

    /**
     * El presupuesto más antiguo de cada paciente que no respondió.
     *
     * @return array{count: int, plans: Collection<int, TreatmentPlan>}
     */
    public function plansAwaitingAnswer(): array
    {
        $plans = TreatmentPlan::query()
            ->awaitingAnswer()
            ->whereHas('patient')
            ->with(['patient.account', 'doctor', 'items'])
            ->oldest('proposed_at')
            ->get()
            ->unique('patient_id');

        return [
            'count' => Patient::query()->withPlanAwaitingAnswer()->count(),
            'plans' => $plans->take(self::LIMIT)->values(),
        ];
    }

    /**
     * Pacientes que dejaron el tratamiento a medias, los que más tiempo llevan sin venir primero.
     * Cada uno trae sus planes sin agendar con los procedimientos que faltan.
     *
     * @return array{count: int, patients: Collection<int, Patient>}
     */
    public function unscheduledPlans(): array
    {
        $patients = Patient::query()
            ->withUnscheduledPlan()
            ->with(['account', 'treatmentPlans' => fn ($plans) => $plans->unscheduled()->with(['items' => fn ($items) => $items->whereIn('status', TreatmentPlanItem::OPEN_STATUSES)])])
            ->orderBy('last_visit_at')
            ->limit(self::LIMIT)
            ->get();

        return [
            'count' => Patient::query()->withUnscheduledPlan()->count(),
            'patients' => $patients,
        ];
    }

    /**
     * Faltaron o cancelaron y nadie les dio otro horario: los más recientes primero, que son los que más vuelven.
     * Cada uno trae en appointments la cita que perdió.
     *
     * @return array{count: int, patients: Collection<int, Patient>}
     */
    public function missedVisits(): array
    {
        $missed = fn ($appointments) => $appointments->missedAndNotRebooked();
        $patients = Patient::query()
            ->missedAndNotRebooked()
            ->withMax(['appointments as missed_at' => $missed], 'starts_at')
            ->with(['account', 'appointments' => fn ($appointments) => $missed($appointments)->with('treatment')->latest('starts_at')])
            ->orderByDesc('missed_at')
            ->limit(self::LIMIT)
            ->get();

        return [
            'count' => Patient::query()->missedAndNotRebooked()->count(),
            'patients' => $patients,
        ];
    }

    /**
     * Cuotas vencidas sin pagar: cuántas y cuánto falta cobrar.
     *
     * @return array{count: int, amount: float}
     */
    public function lateInstallments(): array
    {
        $due = $this->billing->installmentsIn(['late']);

        return ['count' => count($due), 'amount' => round(array_sum($due), 2)];
    }

    /**
     * Para llamar a control (según lo último que se les hizo): los que vinieron hace menos primero (son los que más vuelven).
     *
     * @return array{count: int, patients: Collection<int, Patient>}
     */
    public function checkups(): array
    {
        return [
            'count' => Patient::query()->dueForCheckup()->count(),
            'patients' => Patient::query()->dueForCheckup()->with('account')->orderByDesc('last_visit_at')->limit(self::LIMIT)->get(),
        ];
    }

    /** @return Collection<int, Patient> */
    public function birthdays(): Collection
    {
        return Patient::query()->birthdayOn(today())->with('account')->orderBy('first_name')->get();
    }

    /**
     * A quién pedirle su opinión en Google al terminar el día: lo atendieron hoy, viene seguido (dos atenciones
     * o más), está al día con sus pagos, no faltó a ninguna cita en seis meses y no se le pidió en ese tiempo.
     * Quien ya la recibió hoy sigue en la lista, marcado. Sin el enlace a Google configurado, nadie.
     *
     * @return EloquentCollection<int, Patient> Cada uno con visits_count y la cita de hoy en appointments.
     */
    public function reviewCandidates(): EloquentCollection
    {
        if (blank(app(ClinicInfo::class)->reviewsUrl())) {
            return new EloquentCollection;
        }

        $key = MessageTemplateKey::ReviewRequest;
        $since = today()->subDays(self::CONTACT_DAYS[$key->value]);
        $attendedToday = fn ($appointments) => $appointments->where('status', AppointmentStatus::Completed)->whereDate('starts_at', today());

        return Patient::query()
            ->whereHas('account')
            ->whereHas('appointments', $attendedToday)
            ->whereHas('appointments', fn ($appointments) => $appointments->where('status', AppointmentStatus::Completed), '>=', 2)
            ->whereDoesntHave('appointments', fn ($appointments) => $appointments->where('status', AppointmentStatus::NoShow)->where('starts_at', '>=', $since))
            ->whereDoesntHave('whatsappMessages', fn ($messages) => $messages
                ->where('template_key', $key)
                ->where('status', MessageStatus::Sent)
                ->where('sent_at', '>=', $since)
                ->where('sent_at', '<', today()))
            ->upToDate()
            ->withCount(['appointments as visits_count' => fn ($appointments) => $appointments->where('status', AppointmentStatus::Completed)])
            ->with(['account', 'appointments' => fn ($appointments) => $attendedToday($appointments)->with('treatment')->latest('starts_at')])
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Cuándo se le envió a cada paciente el aviso de este grupo, si fue hace poco.
     *
     * @param  iterable<int>  $patientIds
     * @return array<int, CarbonImmutable> Id del paciente => cuándo
     */
    public function recentlyContacted(iterable $patientIds, MessageTemplateKey $key): array
    {
        $since = today()->subDays(self::CONTACT_DAYS[$key->value] ?? 0);

        return WhatsappMessage::query()
            ->whereIn('patient_id', collect($patientIds)->all())
            ->where('template_key', $key)
            ->where('status', MessageStatus::Sent)
            ->where('sent_at', '>=', $since)
            ->orderBy('sent_at')
            ->get(['patient_id', 'sent_at'])
            ->mapWithKeys(fn (WhatsappMessage $message) => [$message->patient_id => CarbonImmutable::instance($message->sent_at)])
            ->all();
    }

    /**
     * El aviso armado para el paciente, sin guardar. Null si no tiene celular o el aviso no es de seguimiento.
     * Con $plan, habla de ese plan; sin él, del presupuesto más antiguo o de todo lo que le falta.
     */
    public function draft(MessageTemplateKey $key, Patient $patient, ?TreatmentPlan $plan = null): ?WhatsappMessage
    {
        if (! array_key_exists($key->value, self::CONTACT_DAYS) || ! $patient->account) {
            return null;
        }

        $params = ['nombre' => $patient->first_name, 'paciente' => $patient->full_name];

        if ($key === MessageTemplateKey::PlanFollowUp) {
            $plan ??= $patient->treatmentPlans()->awaitingAnswer()->with('doctor')->oldest('proposed_at')->first();
            $params['doctor'] = $plan?->doctor?->name ?? 'el doctor';
        }

        if ($key === MessageTemplateKey::PlanNextSession) {
            $pending = TreatmentPlanItem::query()
                ->whereIn('status', TreatmentPlanItem::OPEN_STATUSES)
                ->when(
                    $plan,
                    fn ($items) => $items->where('treatment_plan_id', $plan->getKey()),
                    fn ($items) => $items->whereHas('plan', fn ($plans) => $plans->unscheduled()->where('patient_id', $patient->getKey())),
                )
                ->orderBy('sort_order')
                ->pluck('description')
                ->unique()
                ->all();
            $params['pendiente'] = $pending === [] ? 'lo que falta' : Arr::join($pending, ', ', ' y ');
        }

        if ($key === MessageTemplateKey::MissedVisit) {
            $missed = app(PatientInsights::class)->missedVisit($patient);

            if (! $missed) {
                return null;
            }

            $params['tratamiento'] = mb_strtolower($missed->treatment->name);
            $params['fecha'] = $missed->starts_at->translatedFormat('l j \d\e F');
        }

        if ($key === MessageTemplateKey::ReviewRequest) {
            $params['opinion'] = app(ClinicInfo::class)->reviewsUrl();

            if (blank($params['opinion'])) {
                return null;
            }
        }

        // El control que le toca según lo último que se le hizo: "control de ortodoncia", "control y limpieza".
        if ($key === MessageTemplateKey::CheckupRecall) {
            $params['control'] = (app(PatientInsights::class)->checkup($patient)['kind'] ?? TreatmentKind::Other)->checkupLabel();
        }

        return $this->outbox->draftForAccount($patient->account, $key, $params, $patient->getKey());
    }
}
