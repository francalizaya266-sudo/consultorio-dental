<?php

namespace App\Services\Booking;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancelledBy;
use App\Enums\ConfirmationChannel;
use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Enums\RescheduleRequestStatus;
use App\Exceptions\BookingException;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Access\MagicLinkService;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\Slot;
use App\Services\Billing\BillingService;
use App\Services\Inventory\InventoryService;
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
    /** Horas en que un paso marcado por error (llegó, no asistió, confirmar, cancelar) todavía se puede deshacer. */
    public const UNDO_HOURS = 24;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PatientChangePolicy $policy,
        private readonly WhatsAppOutbox $outbox,
        private readonly StaffAlerts $alerts,
        private readonly MagicLinkService $links,
        private readonly BillingService $billing,
        private readonly WaitlistService $waitlist,
        private readonly InventoryService $inventory,
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

            if (! $this->availability->forPatient($patient->exists ? $patient->getKey() : null)->isFree($doctor, $data->startsAt, $duration, $buffer)) {
                throw BookingException::slotTaken();
            }

            $patient->save();

            $account->forceFill([
                'privacy_accepted_at' => $data->acceptsPrivacy ? ($account->privacy_accepted_at ?? now()) : $account->privacy_accepted_at,
                'accepts_promotions' => $data->acceptsPromotions || $account->accepts_promotions,
                'promotions_consent_at' => $data->acceptsPromotions ? now() : $account->promotions_consent_at,
                'last_seen_at' => now(),
            ])->save();

            $campaign = match (true) {
                filled($data->promoCode) => Campaign::query()->published()->where('promo_code', strtoupper(trim($data->promoCode)))->first(),
                filled($data->campaignSlug) => Campaign::query()->published()->where('slug', $data->campaignSlug)->first(),
                default => null,
            };

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
            $this->waitlist->settle($appointment);
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation);
            $this->alerts->toReception(
                'Nueva cita web',
                "{$patient->full_name} · {$treatment->name} · {$this->when($appointment)} con {$doctor->name}. Envíale la confirmación por WhatsApp.",
                link: ['label' => 'Enviar la confirmación', 'url' => WhatsappMessageResource::getUrl()],
            );

            return $appointment;
        });
    }

    /**
     * Cita creada por recepción o por el doctor (por ejemplo, la próxima sesión al
     * terminar la consulta). Queda confirmada y se prepara el aviso al paciente (salvo que se pida que no).
     *
     * @param  array{duration_minutes?: int, treatment_plan_item_id?: int|null, origin?: mixed, fixed_date?: bool, internal_notes?: string|null, notify?: bool}  $options
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

            $availability = $this->availability->forPatient($patient->getKey());

            if ($hold = $availability->holdAt($doctor, $startsAt, $duration, $buffer)) {
                throw BookingException::held($hold);
            }

            if (! $availability->isFree($doctor, $startsAt, $duration, $buffer, requireWorkingHours: false)) {
                throw BookingException::overlaps($availability->clashAt($doctor, $startsAt, $duration, $buffer));
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

            // Lo que recepción preguntó al agendar queda también en su ficha: no se le vuelve a preguntar.
            if ($appointment->origin !== null && $patient->origin === null) {
                $patient->update(['origin' => $appointment->origin]);
            }

            $appointment->logEvent('created', 'staff');
            $this->waitlist->settle($appointment);

            // Una cita anotada en una hora que ya pasó (alguien que vino sin cita) no se le anuncia como "tu próxima cita".
            if (($options['notify'] ?? true) && $startsAt->isFuture()) {
                $this->outbox->queueForAppointment($appointment, MessageTemplateKey::AppointmentScheduled);
            }

            if ($by->isDoctor()) {
                $this->alerts->toReception(
                    "{$doctor->name} agendó una cita",
                    "{$patient->full_name} · {$treatment->name} · {$this->when($appointment)}.",
                );
            }

            return $appointment;
        });
    }

    /**
     * Paciente que llega sin cita: se le da el primer hueco de hoy con ese doctor y queda en sala de una vez.
     * No se le prepara el aviso de "cita agendada": lo tenemos delante.
     *
     * @param  array{treatment_plan_item_id?: int|null, origin?: mixed, internal_notes?: string|null}  $options
     */
    public function walkIn(User $by, Patient $patient, Doctor $doctor, Treatment $treatment, array $options = []): Appointment
    {
        $slot = $this->firstRoomToday($doctor, $treatment, $patient->getKey());

        if ($slot === null) {
            throw BookingException::noRoomToday($doctor);
        }

        return DB::transaction(function () use ($by, $patient, $doctor, $treatment, $slot, $options) {
            $appointment = $this->bookFromStaff($by, $patient, $doctor, $treatment, $slot->startsAt, [...$options, 'notify' => false]);
            $this->markArrived($appointment, $by);

            return $appointment;
        });
    }

    /** El primer hueco que le queda hoy a ese doctor para ese tratamiento, desde ahora. Null si ya no tiene. */
    public function firstRoomToday(Doctor $doctor, Treatment $treatment, ?int $patientId = null): ?Slot
    {
        return $this->availability->forPatient($patientId)->slotsFor($doctor, $treatment, today(), earliest: now())->first();
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

        [$freedDoctor, $freedAt] = [$appointment->doctor, $appointment->starts_at];
        $appointment = $this->move($appointment, $appointment->doctor, $startsAt, requireWorkingHours: true, actor: 'patient');

        $appointment->update([
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_via' => ConfirmationChannel::PatientLink,
            'unconfirmed_flagged_at' => null,
        ]);

        $this->closeRescheduleRequests($appointment, 'El paciente movió su cita a otro horario.', 'patient');

        $waitlist = $this->waitlist->freedSlotNotice($freedDoctor, $freedAt, $appointment->patient_id);
        $this->alerts->toReception(
            'Un paciente cambió su horario',
            "{$appointment->patient->full_name} movió su cita a {$this->when($appointment)} con {$appointment->doctor->name}."
                .($waitlist ? ' En el horario que dejó, '.lcfirst($waitlist['text']) : ''),
            link: $waitlist['link'] ?? null,
        );

        return $appointment;
    }

    /**
     * Recepción o el doctor mueven la cita. El paciente recibe el aviso con el
     * nuevo horario y lo confirma con su enlace.
     */
    /**
     * @param  bool  $agreed  El nuevo horario ya se acordó con el paciente (por teléfono, en persona): queda
     *                        confirmada y no se le pide confirmar de nuevo. Lo mismo si se mueve a una hora que
     *                        ya pasó: no hay nada que confirmar ni que avisar.
     */
    public function rescheduleByStaff(Appointment $appointment, CarbonInterface $startsAt, User $by, ?Doctor $doctor = null, bool $agreed = false): Appointment
    {
        $doctor ??= $appointment->doctor;
        $agreed = $agreed || $startsAt->isPast();
        $this->assertCanManage($by, $appointment->doctor);
        $this->assertCanManage($by, $doctor);

        if (! $appointment->status->isChangeable()) {
            throw BookingException::notChangeable();
        }

        if ($doctor->isNot($appointment->doctor)) {
            $this->assertDoctorOffers($doctor, $appointment->treatment);
        }

        [$freedDoctor, $freedAt] = [$appointment->doctor, $appointment->starts_at];
        $appointment = $this->move($appointment, $doctor, $startsAt, requireWorkingHours: false, actor: 'staff');

        $appointment->update($agreed ? [
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_via' => $by->isDoctor() ? ConfirmationChannel::Doctor : ConfirmationChannel::Reception,
            'unconfirmed_flagged_at' => null,
        ] : [
            'status' => AppointmentStatus::Pending,
            'confirmed_at' => null,
            'confirmed_via' => null,
            'unconfirmed_flagged_at' => null,
        ]);

        if ($agreed) {
            // Ya lo sabe: no se le pide confirmar, y el aviso de un cambio anterior ya no sirve.
            $this->outbox->discardPending($appointment, MessageTemplateKey::Rescheduled);
        } else {
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::Rescheduled);
        }

        $this->closeRescheduleRequests($appointment, 'La cita se movió a otro horario.', 'staff', $by);

        $waitlist = $this->waitlist->freedSlotNotice($freedDoctor, $freedAt, $appointment->patient_id);

        if ($by->isDoctor()) {
            $this->alerts->toReception(
                "{$doctor->name} movió una cita",
                "{$appointment->patient->full_name} ahora va el {$this->when($appointment)}."
                    .($agreed ? '' : ' El aviso al paciente está listo para enviar.')
                    .($waitlist ? ' En el horario que dejó, '.lcfirst($waitlist['text']) : ''),
                link: $waitlist['link'] ?? null,
            );
        } elseif ($waitlist) {
            $this->alerts->toReception(
                'Se liberó un horario',
                ucfirst($freedAt->translatedFormat('D j M, g:i a'))." con {$freedDoctor->name}. {$waitlist['text']}",
                link: $waitlist['link'],
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

        $waitlist = $this->waitlistNotice($appointment);
        $this->alerts->toReception(
            'Un paciente canceló su cita',
            "{$appointment->patient->full_name} canceló {$this->when($appointment)} con {$appointment->doctor->name}."
                .($waitlist ? ' '.$waitlist['text'] : ''),
            link: $waitlist['link'] ?? null,
        );
    }

    public function cancelByStaff(Appointment $appointment, User $by, ?string $reason = null, bool $notifyPatient = true): void
    {
        $this->assertCanManage($by, $appointment->doctor);

        $this->cancel($appointment, CancelledBy::Clinic, $reason, $by);

        if ($notifyPatient) {
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::Cancelled);
        }

        $waitlist = $this->waitlistNotice($appointment);

        if ($by->isDoctor()) {
            $this->alerts->toReception(
                "{$appointment->doctor->name} canceló una cita",
                "{$appointment->patient->full_name} · {$this->when($appointment)}.".($waitlist ? ' '.$waitlist['text'] : ''),
                link: $waitlist['link'] ?? null,
            );
        } elseif ($waitlist) {
            $this->alerts->toReception(
                'Se liberó un horario',
                "{$this->when($appointment)} con {$appointment->doctor->name}. {$waitlist['text']}",
                link: $waitlist['link'],
            );
        }
    }

    /** El paciente llegó y está en sala de espera. */
    public function markArrived(Appointment $appointment, User $by): void
    {
        $this->transition($appointment, $by, AppointmentStatus::CheckedIn, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], [
            'checked_in_at' => now(),
        ]);

        $this->alerts->patientArrived($appointment, $by);
    }

    /**
     * Empieza la atención (se guarda la hora real para comparar con lo programado). Si la cita era de un día
     * anterior y se registra después, se toma la hora programada: la real ya no se sabe.
     */
    public function markStarted(Appointment $appointment, User $by): void
    {
        $at = $appointment->isFromAnEarlierDay() ? $appointment->starts_at : now();

        $this->transition($appointment, $by, AppointmentStatus::InProgress, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], [
            'checked_in_at' => $appointment->checked_in_at ?? $at,
            'started_at' => $at,
        ]);
    }

    /**
     * Se atendió. Una cita cuya hora ya terminó (de hoy o de un día anterior) y que quedó sin cerrar también se
     * puede marcar después, aunque no se confirmara ni se anotara que llegó.
     */
    public function markCompleted(Appointment $appointment, User $by): void
    {
        $from = [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress];

        $this->transition($appointment, $by, AppointmentStatus::Completed, $appointment->ends_at->isPast() ? AppointmentStatus::open() : $from, [
            'started_at' => $appointment->started_at ?? $appointment->checked_in_at ?? $appointment->starts_at,
            'finished_at' => $appointment->isFromAnEarlierDay() ? $appointment->ends_at : now(),
        ]);

        $patient = $appointment->patient;
        $patient->forceFill([
            'first_visit_at' => $patient->first_visit_at ?? $appointment->starts_at,
            'last_visit_at' => $appointment->starts_at,
        ])->save();

        // Sin atención escrita (la cerró recepción), se carga el tratamiento de la cita y se descuentan sus materiales.
        $this->billing->chargeAppointment($appointment, $by);
        $this->inventory->useForAppointment($appointment, $by);

        // Los cuidados de ese tratamiento quedan listos en Avisos. Si la cita se cierra días después, ya no sirven.
        if (! $appointment->isFromAnEarlierDay() && filled($appointment->treatment?->aftercare)) {
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::Aftercare, extra: ['cuidados' => trim($appointment->treatment->aftercare)]);
        }
    }

    public function markNoShow(Appointment $appointment, User $by): void
    {
        $this->transition($appointment, $by, AppointmentStatus::NoShow, AppointmentStatus::changeable(), [
            'no_show_at' => now(),
        ]);
    }

    /**
     * El último paso de la cita que todavía se puede deshacer, por si se marcó por error: "llegó", "no asistió",
     * "confirmar" (a mano, no la cita que nace confirmada al agendarla el personal) o "cancelar" (por el
     * consultorio), hecho hace menos de un día. Null si no hay nada que deshacer.
     */
    public function undoableStep(Appointment $appointment): ?AppointmentStatus
    {
        $at = match ($appointment->status) {
            AppointmentStatus::CheckedIn => $appointment->checked_in_at,
            AppointmentStatus::NoShow => $appointment->no_show_at,
            AppointmentStatus::Cancelled => $appointment->cancelled_by === CancelledBy::Clinic ? $appointment->cancelled_at : null,
            AppointmentStatus::Confirmed => $this->wasConfirmedByHand($appointment) ? $appointment->confirmed_at : null,
            default => null,
        };

        return $at !== null && $at->gte(now()->subHours(self::UNDO_HOURS)) ? $appointment->status : null;
    }

    /**
     * Deshace el último paso (ver undoableStep): la cita vuelve a como estaba antes y queda anotado en su historial.
     * Una cita cancelada solo vuelve a la agenda si su horario sigue libre.
     *
     * @return AppointmentStatus El paso que se deshizo.
     */
    public function undoLastStep(Appointment $appointment, User $by): AppointmentStatus
    {
        $this->assertCanManage($by, $appointment->doctor);

        $step = $this->undoableStep($appointment);

        if ($step === null) {
            throw BookingException::nothingToUndo();
        }

        return DB::transaction(function () use ($appointment, $step) {
            if ($step === AppointmentStatus::Cancelled) {
                $this->assertStillFree($appointment);
            }

            $before = $appointment->confirmed_at ? AppointmentStatus::Confirmed : AppointmentStatus::Pending;

            $appointment->update(match ($step) {
                AppointmentStatus::Confirmed => ['status' => AppointmentStatus::Pending, 'confirmed_at' => null, 'confirmed_via' => null],
                AppointmentStatus::CheckedIn => ['status' => $before, 'checked_in_at' => null],
                AppointmentStatus::NoShow => ['status' => $before, 'no_show_at' => null],
                AppointmentStatus::Cancelled => ['status' => $before, 'cancelled_at' => null, 'cancelled_by' => null, 'cancelled_by_user_id' => null, 'cancellation_reason' => null],
            });

            $appointment->logEvent('undone', 'staff', ['meta' => ['step' => $step->value]]);

            if ($step === AppointmentStatus::CheckedIn) {
                // El doctor ya no tiene a nadie esperando: su aviso de "llegó" queda como leído.
                $this->alerts->arrivalAttended($appointment);
            }

            if ($step === AppointmentStatus::Cancelled) {
                // El aviso de la cancelación que aún no se envió ya no corresponde.
                $this->outbox->discardPending($appointment, MessageTemplateKey::Cancelled);
            }

            return $step;
        });
    }

    /**
     * Una solicitud de cambio pendiente deja de tener sentido cuando la cita se mueve por otro lado, se cancela,
     * sigue su curso o ya pasó: se cierra sola, con el motivo, y queda anotado en el historial de la cita.
     *
     * @param  string  $actor  Quién lo provocó: patient | staff | system.
     * @return int Cuántas solicitudes cerró.
     */
    public function closeRescheduleRequests(Appointment $appointment, string $why, string $actor = 'system', ?User $by = null): int
    {
        $closed = $appointment->rescheduleRequests()
            ->where('status', RescheduleRequestStatus::Pending)
            ->update([
                'status' => RescheduleRequestStatus::Expired,
                'resolved_at' => now(),
                'resolved_by_user_id' => $by?->getKey(),
                'response_note' => $why,
            ]);

        if ($closed > 0) {
            $appointment->events()->create(['type' => 'reschedule_expired', 'actor' => $actor, 'user_id' => $by?->getKey(), 'notes' => $why]);
        }

        return $closed;
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

    /** La confirmó una persona del consultorio después de creada (la que agenda el personal ya nace confirmada). */
    private function wasConfirmedByHand(Appointment $appointment): bool
    {
        return in_array($appointment->confirmed_via, [ConfirmationChannel::Reception, ConfirmationChannel::Doctor], true)
            && $appointment->confirmed_at !== null
            && ($appointment->created_at === null || $appointment->confirmed_at->gt($appointment->created_at));
    }

    /** Antes de devolver una cita cancelada a la agenda: su horario no debe haberse ocupado ni apartado mientras tanto. */
    private function assertStillFree(Appointment $appointment): void
    {
        $this->lockAgenda($appointment->doctor);

        $duration = (int) $appointment->starts_at->diffInMinutes($appointment->ends_at, true);
        $availability = $this->availability->forPatient($appointment->patient_id);

        if ($hold = $availability->holdAt($appointment->doctor, $appointment->starts_at, $duration, $appointment->buffer_minutes)) {
            throw BookingException::held($hold);
        }

        if (! $availability->isFree($appointment->doctor, $appointment->starts_at, $duration, $appointment->buffer_minutes, $appointment->getKey(), requireWorkingHours: false)) {
            throw BookingException::slotNoLongerFree();
        }
    }

    private function move(Appointment $appointment, Doctor $doctor, CarbonInterface $startsAt, bool $requireWorkingHours, string $actor): Appointment
    {
        $treatment = $appointment->treatment;
        $duration = $doctor->durationFor($treatment);
        $buffer = $treatment->bufferMinutes();

        return DB::transaction(function () use ($appointment, $doctor, $startsAt, $requireWorkingHours, $actor, $duration, $buffer) {
            $this->lockAgenda($doctor);

            $availability = $this->availability->forPatient($appointment->patient_id);

            if ($actor !== 'patient' && ($hold = $availability->holdAt($doctor, $startsAt, $duration, $buffer))) {
                throw BookingException::held($hold);
            }

            if (! $availability->isFree($doctor, $startsAt, $duration, $buffer, $appointment->getKey(), $requireWorkingHours)) {
                throw $actor === 'patient'
                    ? BookingException::slotTaken()
                    : BookingException::overlaps($availability->clashAt($doctor, $startsAt, $duration, $buffer, $appointment->getKey()));
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

            // Adelantarle la cita es justo lo que esperaba en la lista.
            if ($startsAt->lt($from)) {
                $this->waitlist->settle($appointment);
            }

            // El recordatorio del horario anterior ya no sirve; el enlace sigue sirviendo para el nuevo.
            $this->outbox->discardPending($appointment, MessageTemplateKey::Reminder);
            $appointment->refresh();
            $this->links->extendForAppointment($appointment);

            return $appointment;
        });
    }

    /** @param  list<AppointmentStatus>  $from */
    private function transition(Appointment $appointment, User $by, AppointmentStatus $to, array $from, array $attributes): void
    {
        $this->assertCanManage($by, $appointment->doctor);

        if (! in_array($appointment->status, $from, true)) {
            throw BookingException::notChangeable();
        }

        $wasWaiting = $appointment->status === AppointmentStatus::CheckedIn;

        $appointment->update(['status' => $to] + $attributes);
        $appointment->logEvent($to->value, 'staff');
        $this->closeRescheduleRequests(
            $appointment,
            $to === AppointmentStatus::NoShow ? 'El paciente no asistió a la cita.' : 'El paciente vino a la cita.',
            'staff',
            $by,
        );

        if ($wasWaiting) {
            $this->alerts->arrivalAttended($appointment);
        }
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
        $this->closeRescheduleRequests($appointment, 'La cita se canceló.', $by === CancelledBy::Patient ? 'patient' : 'staff', $user);
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

    /**
     * Si alguien de la lista de espera puede tomar el horario que se liberó: el texto para la alerta
     * y el botón que abre la agenda con el horario ofrecido al primero que lo espera.
     *
     * @return array{text: string, link: array{label: string, url: string}}|null
     */
    private function waitlistNotice(Appointment $appointment): ?array
    {
        return $this->waitlist->freedSlotNotice($appointment->doctor, $appointment->starts_at, $appointment->patient_id);
    }

    private function when(Appointment $appointment): string
    {
        return $appointment->starts_at->translatedFormat('D j M, g:i a');
    }
}
