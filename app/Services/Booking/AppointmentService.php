<?php

namespace App\Services\Booking;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancelledBy;
use App\Enums\ConfirmationChannel;
use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Enums\WaitlistStatus;
use App\Exceptions\BookingException;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Agenda\AvailabilityService;
use App\Services\StaffAlerts;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Reserva, confirmación, cambio de horario y cancelación de citas.
 * Toda escritura en la agenda pasa por aquí para que nunca haya dos citas cruzadas.
 */
class AppointmentService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PatientChangePolicy $policy,
        private readonly WhatsAppOutbox $outbox,
        private readonly StaffAlerts $alerts,
    ) {}

    /**
     * Reserva desde la web: queda reservada al instante si el horario está libre.
     * El paciente confirma después con el enlace que recepción le envía.
     */
    public function bookFromWeb(WebBookingData $data, ?RememberedDevice $device = null): Appointment
    {
        $doctor = Doctor::query()->active()->findOrFail($data->doctorId);
        $treatment = Treatment::query()->active()->findOrFail($data->treatmentId);

        if (! $treatment->online_bookable || ! $doctor->accepts_online_booking) {
            throw BookingException::treatmentNotBookableOnline();
        }

        $this->assertDoctorOffers($doctor, $treatment);
        $this->assertWebTimeWindow($data->startsAt);

        $phone = Phone::normalize($data->phone);
        $recognized = $device !== null && $device->account->phone === $phone;

        return DB::transaction(function () use ($data, $doctor, $treatment, $phone, $recognized) {
            $account = Account::withTrashed()->firstOrCreate(['phone' => $phone]);

            if ($account->trashed()) {
                $account->restore();
            }

            if ($account->isBlocked()) {
                throw BookingException::accountBlocked();
            }

            $patient = $this->resolveWebPatient($account, $data, $recognized);

            if ($patient->exists && $patient->hasActiveTreatment()) {
                throw BookingException::patientInTreatment();
            }

            $this->assertWebLimits($account, $patient);

            $duration = $doctor->durationFor($treatment);
            $buffer = $treatment->bufferMinutes();

            $this->lockAgenda($doctor);

            if (! $this->availability->isFree($doctor, $data->startsAt, $duration, $buffer)) {
                throw BookingException::slotTaken();
            }

            $patient->save();

            $account->forceFill([
                'privacy_accepted_at' => $data->acceptsPrivacy ? ($account->privacy_accepted_at ?? now()) : $account->privacy_accepted_at,
                'accepts_promotions' => $data->acceptsPromotions || $account->accepts_promotions,
                'promotions_consent_at' => $data->acceptsPromotions ? now() : $account->promotions_consent_at,
                'last_seen_at' => now(),
            ])->save();

            $campaign = $data->promoCode
                ? Campaign::query()->published()->where('promo_code', strtoupper(trim($data->promoCode)))->first()
                : null;

            $appointment = Appointment::create([
                'patient_id' => $patient->getKey(),
                'doctor_id' => $doctor->getKey(),
                'treatment_id' => $treatment->getKey(),
                'starts_at' => $data->startsAt,
                'ends_at' => $data->startsAt->copy()->addMinutes($duration),
                'buffer_minutes' => $buffer,
                'status' => AppointmentStatus::Pending,
                'booked_via' => BookingChannel::Web,
                'origin' => $campaign ? AppointmentOrigin::Campaign : $data->origin,
                'campaign_id' => $campaign?->hasSlotsLeft() ? $campaign->getKey() : null,
                'promo_code' => $data->promoCode,
                'is_new_patient' => ! $patient->appointments()->where('status', AppointmentStatus::Completed)->exists(),
                'from_verified_device' => $recognized,
                'patient_note' => $data->note,
            ]);

            $appointment->logEvent('created', 'patient');
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation);
            $this->alerts->toReception(
                'Nueva cita web',
                "{$patient->full_name} · {$treatment->name} · {$this->when($appointment)} con {$doctor->name}. Envíale la confirmación por WhatsApp.",
            );

            return $appointment;
        });
    }

    /**
     * Cita creada por recepción o por el doctor (por ejemplo, la próxima sesión al
     * terminar la consulta). Queda confirmada y se prepara el aviso al paciente.
     */
    public function bookFromStaff(
        User $by,
        Patient $patient,
        Doctor $doctor,
        Treatment $treatment,
        CarbonInterface $startsAt,
        array $options = [],
    ): Appointment {
        $this->assertCanManage($by, $doctor);

        $duration = (int) ($options['duration_minutes'] ?? $doctor->durationFor($treatment));
        $buffer = $treatment->bufferMinutes();

        return DB::transaction(function () use ($by, $patient, $doctor, $treatment, $startsAt, $options, $duration, $buffer) {
            $this->lockAgenda($doctor);

            if (! $this->availability->isFree($doctor, $startsAt, $duration, $buffer, requireWorkingHours: false)) {
                throw BookingException::overlaps();
            }

            $appointment = Appointment::create([
                'patient_id' => $patient->getKey(),
                'doctor_id' => $doctor->getKey(),
                'treatment_id' => $treatment->getKey(),
                'treatment_plan_item_id' => $options['treatment_plan_item_id'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($duration),
                'buffer_minutes' => $buffer,
                'status' => AppointmentStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_via' => $by->isDoctor() ? ConfirmationChannel::Doctor : ConfirmationChannel::Reception,
                'booked_via' => $by->isDoctor() ? BookingChannel::Doctor : BookingChannel::Reception,
                'origin' => $options['origin'] ?? null,
                'fixed_date' => (bool) ($options['fixed_date'] ?? false),
                'is_new_patient' => ! $patient->appointments()->where('status', AppointmentStatus::Completed)->exists(),
                'internal_notes' => $options['internal_notes'] ?? null,
                'created_by_user_id' => $by->getKey(),
            ]);

            $appointment->logEvent('created', 'staff');
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::AppointmentScheduled);

            if ($by->isDoctor()) {
                $this->alerts->toReception(
                    "{$doctor->name} agendó una cita",
                    "{$patient->full_name} · {$treatment->name} · {$this->when($appointment)}.",
                );
            }

            return $appointment;
        });
    }

    /** El paciente confirma su asistencia con el botón de su enlace personal. */
    public function confirmByPatient(Appointment $appointment): void
    {
        if ($appointment->status !== AppointmentStatus::Pending) {
            return;
        }

        $appointment->update([
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_via' => ConfirmationChannel::PatientLink,
            'unconfirmed_flagged_at' => null,
        ]);

        $appointment->logEvent('confirmed', 'patient');
    }

    /** Recepción confirma (por ejemplo, después de llamar al paciente). */
    public function confirmByStaff(Appointment $appointment, User $by): void
    {
        $this->assertCanManage($by, $appointment->doctor);

        if ($appointment->status !== AppointmentStatus::Pending) {
            return;
        }

        $appointment->update([
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_via' => $by->isDoctor() ? ConfirmationChannel::Doctor : ConfirmationChannel::Reception,
            'unconfirmed_flagged_at' => null,
        ]);

        $appointment->logEvent('confirmed', 'staff');
    }

    /**
     * Nivel 1: el paciente elige otro horario libre y el cambio se hace solo
     * (faltan más de 24 h, mismo doctor y tratamiento, sin fecha fija).
     */
    public function rescheduleByPatient(Appointment $appointment, CarbonInterface $startsAt): Appointment
    {
        if (! $this->policy->canChange($appointment)
            || $this->policy->rescheduleMode($appointment) !== RescheduleMode::Automatic) {
            throw BookingException::notChangeable();
        }

        $this->assertWebTimeWindow($startsAt);

        $appointment = $this->move($appointment, $appointment->doctor, $startsAt, requireWorkingHours: true, actor: 'patient');

        $appointment->update([
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_via' => ConfirmationChannel::PatientLink,
            'unconfirmed_flagged_at' => null,
        ]);

        $this->alerts->toReception(
            'Un paciente cambió su horario',
            "{$appointment->patient->full_name} movió su cita a {$this->when($appointment)} con {$appointment->doctor->name}.",
        );

        return $appointment;
    }

    /**
     * Recepción o el doctor mueven la cita. El paciente recibe el aviso con el
     * nuevo horario y lo confirma con su enlace.
     */
    public function rescheduleByStaff(Appointment $appointment, CarbonInterface $startsAt, User $by, ?Doctor $doctor = null): Appointment
    {
        $doctor ??= $appointment->doctor;
        $this->assertCanManage($by, $appointment->doctor);
        $this->assertCanManage($by, $doctor);

        if (! $appointment->status->isChangeable()) {
            throw BookingException::notChangeable();
        }

        if ($doctor->isNot($appointment->doctor)) {
            $this->assertDoctorOffers($doctor, $appointment->treatment);
        }

        $appointment = $this->move($appointment, $doctor, $startsAt, requireWorkingHours: false, actor: 'staff');

        $appointment->update([
            'status' => AppointmentStatus::Pending,
            'confirmed_at' => null,
            'confirmed_via' => null,
            'unconfirmed_flagged_at' => null,
        ]);

        $this->outbox->queueForAppointment($appointment, MessageTemplateKey::Rescheduled);

        if ($by->isDoctor()) {
            $this->alerts->toReception(
                "{$doctor->name} movió una cita",
                "{$appointment->patient->full_name} ahora va el {$this->when($appointment)}. El aviso al paciente está listo para enviar.",
            );
        }

        return $appointment;
    }

    public function cancelByPatient(Appointment $appointment, ?string $reason = null): void
    {
        if (! $this->policy->canSelfCancel($appointment)) {
            throw BookingException::tooLateToCancel($this->policy->selfCancelMinHours());
        }

        $this->cancel($appointment, CancelledBy::Patient, $reason);

        $waiting = $this->waitlistCandidates($appointment);
        $this->alerts->toReception(
            'Un paciente canceló su cita',
            "{$appointment->patient->full_name} canceló {$this->when($appointment)} con {$appointment->doctor->name}."
                .($waiting > 0 ? " Hay {$waiting} paciente(s) en lista de espera para ese horario." : ''),
        );
    }

    public function cancelByStaff(Appointment $appointment, User $by, ?string $reason = null, bool $notifyPatient = true): void
    {
        $this->assertCanManage($by, $appointment->doctor);

        $this->cancel($appointment, CancelledBy::Clinic, $reason, $by);

        if ($notifyPatient) {
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::Cancelled);
        }

        if ($by->isDoctor()) {
            $this->alerts->toReception(
                "{$appointment->doctor->name} canceló una cita",
                "{$appointment->patient->full_name} · {$this->when($appointment)}.",
            );
        }
    }

    /** El paciente llegó y está en sala de espera. */
    public function markArrived(Appointment $appointment, User $by): void
    {
        $this->transition($appointment, $by, AppointmentStatus::CheckedIn, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], [
            'checked_in_at' => now(),
        ]);
    }

    /** Empieza la atención (se guarda la hora real para comparar con lo programado). */
    public function markStarted(Appointment $appointment, User $by): void
    {
        $this->transition($appointment, $by, AppointmentStatus::InProgress, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], [
            'checked_in_at' => $appointment->checked_in_at ?? now(),
            'started_at' => now(),
        ]);
    }

    public function markCompleted(Appointment $appointment, User $by): void
    {
        $this->transition($appointment, $by, AppointmentStatus::Completed, [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], [
            'started_at' => $appointment->started_at ?? $appointment->checked_in_at ?? $appointment->starts_at,
            'finished_at' => now(),
        ]);

        $patient = $appointment->patient;
        $patient->forceFill([
            'first_visit_at' => $patient->first_visit_at ?? $appointment->starts_at,
            'last_visit_at' => $appointment->starts_at,
        ])->save();
    }

    public function markNoShow(Appointment $appointment, User $by): void
    {
        $this->transition($appointment, $by, AppointmentStatus::NoShow, AppointmentStatus::changeable(), [
            'no_show_at' => now(),
        ]);
    }

    /** Asegura que el doctor (o recepción) solo toque las agendas que le corresponden. */
    public function assertCanManage(User $user, Doctor $doctor): void
    {
        if ($user->managesAllAgendas()) {
            return;
        }

        if ($user->isDoctor() && $user->doctorId() === $doctor->getKey()) {
            return;
        }

        throw new AuthorizationException('Solo puedes manejar tu propia agenda.');
    }

    private function move(Appointment $appointment, Doctor $doctor, CarbonInterface $startsAt, bool $requireWorkingHours, string $actor): Appointment
    {
        $treatment = $appointment->treatment;
        $duration = $doctor->durationFor($treatment);
        $buffer = $treatment->bufferMinutes();

        return DB::transaction(function () use ($appointment, $doctor, $startsAt, $requireWorkingHours, $actor, $duration, $buffer) {
            $this->lockAgenda($doctor);

            if (! $this->availability->isFree($doctor, $startsAt, $duration, $buffer, $appointment->getKey(), $requireWorkingHours)) {
                throw $actor === 'patient' ? BookingException::slotTaken() : BookingException::overlaps();
            }

            $from = $appointment->starts_at;

            $appointment->update([
                'doctor_id' => $doctor->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($duration),
                'buffer_minutes' => $buffer,
                'reschedule_count' => $appointment->reschedule_count + 1,
            ]);

            $appointment->logEvent('rescheduled', $actor, ['from_starts_at' => $from, 'to_starts_at' => $startsAt]);

            // El recordatorio del horario anterior ya no sirve.
            $this->outbox->discardPending($appointment, MessageTemplateKey::Reminder);

            return $appointment->refresh();
        });
    }

    /** @param  list<AppointmentStatus>  $from */
    private function transition(Appointment $appointment, User $by, AppointmentStatus $to, array $from, array $attributes): void
    {
        $this->assertCanManage($by, $appointment->doctor);

        if (! in_array($appointment->status, $from, true)) {
            throw BookingException::notChangeable();
        }

        $appointment->update(['status' => $to] + $attributes);
        $appointment->logEvent($to->value, 'staff');
    }

    private function cancel(Appointment $appointment, CancelledBy $by, ?string $reason, ?User $user = null): void
    {
        if (! $appointment->status->isChangeable()) {
            throw BookingException::notChangeable();
        }

        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancelled_by_user_id' => $user?->getKey(),
            'cancellation_reason' => $reason,
        ]);

        $appointment->logEvent('cancelled', $by === CancelledBy::Patient ? 'patient' : 'staff', ['notes' => $reason]);
        $this->outbox->discardPending($appointment);
    }

    /**
     * Desde un dispositivo no recordado no se muestran ni se eligen familiares
     * (para no exponer datos). Se reutiliza el paciente si el nombre coincide.
     */
    private function resolveWebPatient(Account $account, WebBookingData $data, bool $recognized): Patient
    {
        if ($data->patientId !== null) {
            if (! $recognized) {
                throw new AuthorizationException('Para elegir a un familiar registrado, abre la web desde tu enlace de WhatsApp.');
            }

            return $account->patients()->findOrFail($data->patientId);
        }

        $existing = $account->patients()
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower(trim((string) $data->firstName))])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower(trim((string) $data->lastName))])
            ->first();

        if ($existing) {
            return $existing;
        }

        $hasHolder = $account->patients()->where('relationship', PatientRelationship::Self)->exists();

        return new Patient([
            'account_id' => $account->getKey(),
            'first_name' => trim((string) $data->firstName),
            'last_name' => trim((string) $data->lastName),
            'birth_date' => $data->birthDate,
            'relationship' => $hasHolder && $data->relationship === PatientRelationship::Self
                ? PatientRelationship::Other
                : $data->relationship,
            'origin' => $data->origin,
        ]);
    }

    private function assertWebLimits(Account $account, Patient $patient): void
    {
        $futureWeb = fn () => Appointment::query()
            ->where('booked_via', BookingChannel::Web)
            ->whereIn('status', AppointmentStatus::changeable())
            ->where('starts_at', '>=', now());

        $perPatient = (int) Setting::get('booking.max_web_appointments_per_patient', 2);

        if ($patient->exists && $futureWeb()->whereBelongsTo($patient)->count() >= $perPatient) {
            throw BookingException::patientLimit($perPatient);
        }

        $perPhone = $account->webBookingLimit();
        $accountCount = $futureWeb()->whereHas('patient', fn ($q) => $q->whereBelongsTo($account))->count();

        if ($accountCount >= $perPhone) {
            throw BookingException::phoneLimit($perPhone);
        }
    }

    private function assertWebTimeWindow(CarbonInterface $startsAt): void
    {
        if ($startsAt->lt(now()->addMinutes((int) Setting::get('booking.min_lead_minutes', 120)))) {
            throw BookingException::tooSoon();
        }

        $horizon = (int) Setting::get('booking.horizon_days', 45);

        if ($startsAt->gt(now()->addDays($horizon)->endOfDay())) {
            throw BookingException::tooFar($horizon);
        }
    }

    private function assertDoctorOffers(Doctor $doctor, Treatment $treatment): void
    {
        if (! $doctor->treatments()->whereKey($treatment->getKey())->exists()) {
            throw BookingException::doctorDoesNotOfferTreatment();
        }
    }

    /** Bloquea la fila del doctor mientras dura la transacción: dos reservas no pueden cruzarse. */
    private function lockAgenda(Doctor $doctor): void
    {
        Doctor::query()->whereKey($doctor->getKey())->lockForUpdate()->first();
    }

    private function waitlistCandidates(Appointment $appointment): int
    {
        return WaitlistEntry::query()
            ->where('status', WaitlistStatus::Waiting)
            ->where(fn ($q) => $q->whereNull('treatment_id')->orWhere('treatment_id', $appointment->treatment_id))
            ->where(fn ($q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $appointment->doctor_id))
            ->count();
    }

    private function when(Appointment $appointment): string
    {
        return $appointment->starts_at->translatedFormat('D j M, g:i a');
    }
}
