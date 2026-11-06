<?php

namespace App\Services\Clinical;

use App\Enums\AppointmentStatus;
use App\Enums\VisitType;
use App\Exceptions\ClinicalException;
use App\Models\Appointment;
use App\Models\ClinicalTemplate;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Booking\AppointmentService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Atenciones clínicas: se abren desde la cita (o sin cita), llegan con la plantilla del
 * tratamiento, el odontograma de la consulta anterior y lo programado ya marcado como hecho.
 * El doctor corrige lo que se hizo de verdad; al terminar, eso se cobra y cierra la cita.
 */
final class ConsultationService
{
    /** Campos de la atención que una plantilla puede precargar. */
    public const TEMPLATE_FIELDS = ['chief_complaint', 'clinical_exam', 'diagnosis_notes', 'procedures', 'indications', 'next_steps'];

    /** Así empieza el motivo de una sesión del plan, que se escribe solo. */
    public const PLANNED_VISIT_REASON = 'Continúa su plan de tratamiento: ';

    /** Horas en que el doctor puede reabrir una atención cerrada para corregirla. */
    public const REOPEN_HOURS = 24;

    public function __construct(
        private readonly OdontogramService $odontograms,
        private readonly AppointmentService $appointments,
        private readonly TreatmentPlanService $plans,
        private readonly PrescriptionService $prescriptions,
        private readonly BillingService $billing,
        private readonly InventoryService $inventory,
    ) {}

    /** Abre la atención de una cita (o retoma la que ya estaba abierta). */
    public function startFromAppointment(Appointment $appointment, User $by): Consultation
    {
        $doctor = $this->doctorOf($by);

        if ($doctor->isNot($appointment->doctor)) {
            throw ClinicalException::notYourAppointment();
        }

        return DB::transaction(function () use ($appointment, $by, $doctor) {
            $existing = Consultation::query()->where('appointment_id', $appointment->getKey())->first();

            if ($existing) {
                return $existing;
            }

            if (in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true)) {
                $this->appointments->markStarted($appointment, $by);
            }

            return $this->open($appointment->patient, $doctor, $by, $appointment->treatment, $appointment);
        });
    }

    /** Atención sin cita (urgencia o paciente que pasó directo). */
    public function startWithoutAppointment(Patient $patient, User $by, ?Treatment $treatment = null): Consultation
    {
        $doctor = $this->doctorOf($by);

        return DB::transaction(fn () => $this->open($patient, $doctor, $by, $treatment));
    }

    /** Agrega el texto de la plantilla: llena los campos vacíos y suma al final de los que ya tienen texto. */
    public function applyTemplate(Consultation $consultation, ClinicalTemplate $template): void
    {
        $this->assertWritable($consultation);

        DB::transaction(function () use ($consultation, $template) {
            $consultation->update($this->templateValues($consultation, $template));

            // A un niño no se le copia la receta de la plantilla: va en jarabe y por su peso.
            if (! $consultation->patient->isChild() && $medications = $this->prescriptions->fromTemplate($template)) {
                $this->prescriptions->syncForConsultation($consultation, PrescriptionService::merge($this->prescriptions->itemsOf($consultation), $medications));
            }
        });
    }

    /**
     * Termina la atención: queda cerrada, la cita pasa a "atendida", avanza el plan, se carga lo
     * realizado y salen del inventario los materiales usados (los que corrigió el doctor o, si no,
     * la receta de lo trabajado).
     *
     * @param  list<array{supply_id: int|string|null, quantity: float|int|string|null, batch_id?: int|string|null}>|null  $suppliesUsed
     */
    public function close(Consultation $consultation, User $by, ?array $suppliesUsed = null): void
    {
        $this->assertWritable($consultation, $by);

        DB::transaction(function () use ($consultation, $by, $suppliesUsed) {
            $consultation->update(['closed_at' => now()]);
            $this->plans->syncConsultation($consultation);

            $appointment = $consultation->appointment;

            if ($appointment && in_array($appointment->status, [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true)) {
                $this->appointments->markCompleted($appointment, $by);
            }

            $this->billing->chargeConsultation($consultation, $by);
            $this->inventory->useForConsultation($consultation, $suppliesUsed ?? $this->inventory->plannedUsage($consultation), $by);
        });
    }

    /** Permite corregir una atención recién cerrada; todo cambio queda en la auditoría. */
    public function reopen(Consultation $consultation, User $by): void
    {
        if ($by->doctorId() !== $consultation->doctor_id) {
            throw ClinicalException::notAuthor();
        }

        if (! $this->canStillReopen($consultation)) {
            throw ClinicalException::reopenExpired();
        }

        DB::transaction(function () use ($consultation, $by) {
            $consultation->update(['closed_at' => null]);
            $this->plans->syncConsultation($consultation);
            // Lo usado vuelve al stock; al terminar de nuevo se descuenta lo que quede registrado.
            $this->inventory->revertConsultation($consultation, $by);
        });
    }

    public function canStillReopen(Consultation $consultation): bool
    {
        return $consultation->closed_at !== null
            && $consultation->closed_at->gt(now()->subHours(self::REOPEN_HOURS));
    }

    /** Plantilla del tratamiento: primero la propia del doctor, luego la compartida. */
    public function templateFor(?Treatment $treatment, User $user): ?ClinicalTemplate
    {
        if (! $treatment) {
            return null;
        }

        return ClinicalTemplate::query()
            ->where('is_active', true)
            ->whereBelongsTo($treatment)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $user->getKey()))
            ->orderByRaw('user_id is null')
            ->first();
    }

    public function assertWritable(Consultation $consultation, ?User $by = null): void
    {
        if ($by && $by->doctorId() !== $consultation->doctor_id) {
            throw ClinicalException::notAuthor();
        }

        if ($consultation->closed_at) {
            throw ClinicalException::closed();
        }
    }

    private function open(Patient $patient, Doctor $doctor, User $by, ?Treatment $treatment, ?Appointment $appointment = null): Consultation
    {
        $consultation = Consultation::create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'appointment_id' => $appointment?->getKey(),
            // Si se registra otro día (la cita quedó sin cerrar), queda con la fecha y la hora de la cita.
            'attended_at' => $appointment?->isFromAnEarlierDay() ? $appointment->starts_at : now(),
        ]);

        if ($template = $this->templateFor($treatment, $by)) {
            $consultation->update($this->templateValues($consultation, $template));

            if (! $patient->isChild()) {
                $this->prescriptions->syncForConsultation($consultation, $this->prescriptions->fromTemplate($template));
            }
        }

        $this->markScheduledAsDone($consultation, $patient, $treatment, $appointment);
        $this->noteVisitReason($consultation);
        $this->odontograms->forConsultation($consultation);

        return $consultation;
    }

    /**
     * Lo programado llega marcado como hecho, y el doctor quita lo que no se hizo: la sesión del plan si la
     * cita es una; si es suelta pero su tratamiento está pendiente en un solo procedimiento de un plan
     * aceptado, ese procedimiento (así se cobra al precio del plan y el plan avanza); si no, el tratamiento.
     */
    private function markScheduledAsDone(Consultation $consultation, Patient $patient, ?Treatment $treatment, ?Appointment $appointment): void
    {
        if ($appointment?->treatment_plan_item_id !== null) {
            $item = $appointment->planItem;

            if ($item?->isOpen() && $item->plan->isOpen()) {
                $consultation->planItems()->attach($item);
            }

            return;
        }

        if (! $treatment) {
            return;
        }

        $pending = $this->plans->workableItems($patient)->filter(fn (TreatmentPlanItem $item) => $item->treatment_id === $treatment->getKey()
            && in_array($item->plan->status, TreatmentPlan::ACCEPTED_STATUSES, true));

        if ($pending->count() === 1) {
            $consultation->planItems()->attach($pending->first());
        } else {
            $consultation->treatments()->attach($treatment);
        }
    }

    /** El motivo que se escribe solo en una sesión del plan o en un control: si sigue así, nadie anotó nada nuevo. */
    public static function isAutomaticReason(Consultation $consultation, ?string $reason): bool
    {
        $reason = trim((string) $reason);

        return $reason === ''
            || str_starts_with($reason, self::PLANNED_VISIT_REASON)
            || $reason === $consultation->mainTreatment()?->name.'.';
    }

    /**
     * En una sesión del plan o en un control, el motivo es ese: la historia dice por qué vino sin que nadie lo
     * escriba. En la sesión del plan, además, la evolución empieza con la pieza que se trabaja.
     */
    private function noteVisitReason(Consultation $consultation): void
    {
        $type = $consultation->visitType();

        if (filled($consultation->chief_complaint) || ! $type->repeatsAnotherVisit()) {
            return;
        }

        if ($type === VisitType::Control) {
            $consultation->update(['chief_complaint' => $consultation->mainTreatment()?->name.'.']);

            return;
        }

        $planned = $consultation->plannedWork();
        $item = $planned->count() === 1 ? $planned->first() : null;

        $consultation->update([
            'chief_complaint' => self::PLANNED_VISIT_REASON.$planned->map(fn (TreatmentPlanItem $item) => $item->label())->implode(', ').'.',
            'procedure_details' => $item && filled($item->tooth) && $consultation->procedureKind()->hasTooth()
                ? array_filter(['tooth' => $item->tooth, 'surfaces' => $item->surfaces], 'filled')
                : $consultation->procedure_details,
        ]);
    }

    /** @return array<string, string> */
    private function templateValues(Consultation $consultation, ClinicalTemplate $template): array
    {
        return self::mergeTemplate($consultation->only(self::TEMPLATE_FIELDS), $template);
    }

    /**
     * Texto de la plantilla sumado al que ya hay: campos vacíos se llenan, los demás se completan al final.
     *
     * @param  array<string, string|null>  $current
     * @return array<string, string>
     */
    public static function mergeTemplate(array $current, ClinicalTemplate $template): array
    {
        return collect(self::TEMPLATE_FIELDS)
            ->filter(fn (string $field) => filled($template->content[$field] ?? null))
            ->mapWithKeys(function (string $field) use ($current, $template) {
                $existing = trim((string) ($current[$field] ?? ''));
                $text = trim($template->content[$field]);

                return [$field => match (true) {
                    $existing === '' => $text,
                    str_contains($existing, $text) => $existing,
                    default => $existing."\n".$text,
                }];
            })
            ->all();
    }

    private function doctorOf(User $user): Doctor
    {
        return $user->doctor ?? throw ClinicalException::noDoctorProfile();
    }
}
