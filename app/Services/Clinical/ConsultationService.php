<?php

namespace App\Services\Clinical;

use App\Enums\AppointmentStatus;
use App\Exceptions\ClinicalException;
use App\Models\Appointment;
use App\Models\ClinicalTemplate;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Booking\AppointmentService;
use Illuminate\Support\Facades\DB;

/**
 * Atenciones clínicas: se abren desde la cita (o sin cita), llegan con la plantilla del
 * tratamiento y el odontograma de la consulta anterior, y al terminar cierran la cita.
 */
final class ConsultationService
{
    /** Campos de la atención que una plantilla puede precargar. */
    public const TEMPLATE_FIELDS = ['chief_complaint', 'clinical_exam', 'diagnosis_notes', 'procedures', 'indications', 'next_steps'];

    /** Horas en que el doctor puede reabrir una atención cerrada para corregirla. */
    public const REOPEN_HOURS = 24;

    public function __construct(
        private readonly OdontogramService $odontograms,
        private readonly AppointmentService $appointments,
        private readonly TreatmentPlanService $plans,
        private readonly PrescriptionService $prescriptions,
        private readonly BillingService $billing,
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

            if ($medications = $this->prescriptions->fromTemplate($template)) {
                $this->prescriptions->syncForConsultation($consultation, PrescriptionService::merge($this->prescriptions->itemsOf($consultation), $medications));
            }
        });
    }

    /** Termina la atención: queda cerrada, la cita pasa a "atendida", avanza el plan y se carga lo realizado. */
    public function close(Consultation $consultation, User $by): void
    {
        $this->assertWritable($consultation, $by);

        DB::transaction(function () use ($consultation, $by) {
            $consultation->update(['closed_at' => now()]);
            $this->plans->syncConsultation($consultation);

            $appointment = $consultation->appointment;

            if ($appointment && in_array($appointment->status, [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true)) {
                $this->appointments->markCompleted($appointment, $by);
            }

            $this->billing->chargeConsultation($consultation, $by);
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

        DB::transaction(function () use ($consultation) {
            $consultation->update(['closed_at' => null]);
            $this->plans->syncConsultation($consultation);
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
            'attended_at' => now(),
        ]);

        if ($template = $this->templateFor($treatment, $by)) {
            $consultation->update($this->templateValues($consultation, $template));
            $this->prescriptions->syncForConsultation($consultation, $this->prescriptions->fromTemplate($template));
        }

        // Si la cita es una sesión del plan, ya queda marcada como lo que se trabaja hoy.
        if ($appointment?->planItem?->isOpen() && $appointment->planItem->plan->isOpen()) {
            $consultation->planItems()->attach($appointment->treatment_plan_item_id);
        }

        $this->odontograms->forConsultation($consultation);

        return $consultation;
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
