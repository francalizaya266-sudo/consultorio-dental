<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\ConfirmationChannel;
use App\Enums\MessageTemplateKey;
use App\Enums\RequestedBy;
use App\Enums\RescheduleRequestStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\RescheduleRequest;
use App\Models\RescheduleRequestOption;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use App\Services\StaffAlerts;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Nivel 2 de cambios: el paciente propone hasta 3 horarios y recepción o el
 * doctor aprueba uno con un clic. Mientras tanto, la cita original se mantiene.
 */
class RescheduleRequestService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AppointmentService $appointments,
        private readonly PatientChangePolicy $policy,
        private readonly WhatsAppOutbox $outbox,
        private readonly StaffAlerts $alerts,
    ) {}

    /** @param  list<string|\DateTimeInterface>  $proposedStarts */
    public function requestByPatient(
        Appointment $appointment,
        array $proposedStarts,
        ?string $reason = null,
        ?int $doctorId = null,
        ?int $treatmentId = null,
    ): RescheduleRequest {
        if (! $this->policy->canChange($appointment)) {
            throw BookingException::notChangeable();
        }

        $max = (int) Setting::get('booking.max_reschedule_options', 3);

        if (count($proposedStarts) === 0 || count($proposedStarts) > $max) {
            throw BookingException::tooManyOptions($max);
        }

        $doctor = $doctorId ? Doctor::query()->active()->findOrFail($doctorId) : $appointment->doctor;
        $treatment = $treatmentId ? Treatment::query()->active()->findOrFail($treatmentId) : $appointment->treatment;
        $duration = $doctor->durationFor($treatment);
        $buffer = $treatment->bufferMinutes();

        return DB::transaction(function () use ($appointment, $proposedStarts, $reason, $doctor, $treatment, $duration, $buffer) {
            // Una sola solicitud abierta por cita: la nueva reemplaza a la anterior.
            $appointment->rescheduleRequests()
                ->where('status', RescheduleRequestStatus::Pending)
                ->update(['status' => RescheduleRequestStatus::Withdrawn, 'resolved_at' => now()]);

            $request = $appointment->rescheduleRequests()->create([
                'requested_by' => RequestedBy::Patient,
                'status' => RescheduleRequestStatus::Pending,
                'requested_doctor_id' => $doctor->isNot($appointment->doctor) ? $doctor->getKey() : null,
                'requested_treatment_id' => $treatment->isNot($appointment->treatment) ? $treatment->getKey() : null,
                'reason' => $reason,
            ]);

            foreach ($proposedStarts as $start) {
                $start = CarbonImmutable::parse($start);

                if (! $this->availability->isFree($doctor, $start, $duration, $buffer, $appointment->getKey())) {
                    throw BookingException::slotTaken();
                }

                $request->options()->create([
                    'doctor_id' => $doctor->getKey(),
                    'starts_at' => $start,
                    'ends_at' => $start->addMinutes($duration),
                ]);
            }

            $appointment->logEvent('reschedule_requested', 'patient', ['notes' => $reason]);

            $this->alerts->toReception(
                'Solicitud de cambio de horario',
                "{$appointment->patient->full_name} pide mover su cita del {$appointment->starts_at->translatedFormat('D j M, g:i a')}. Propuso {$request->options()->count()} horario(s).",
            );
            $this->alerts->toUser($appointment->doctor->user, 'Solicitud de cambio de horario', $appointment->patient->full_name);

            return $request;
        });
    }

    public function approve(RescheduleRequest $request, RescheduleRequestOption $option, User $by): Appointment
    {
        if ($request->status !== RescheduleRequestStatus::Pending || $option->reschedule_request_id !== $request->getKey()) {
            throw BookingException::notChangeable();
        }

        return DB::transaction(function () use ($request, $option, $by) {
            $appointment = $request->appointment;

            if ($request->requested_treatment_id) {
                $appointment->update(['treatment_id' => $request->requested_treatment_id]);
            }

            $appointment = $this->appointments->rescheduleByStaff($appointment, $option->starts_at, $by, $option->doctor);

            // El paciente eligió ese horario: no hace falta que vuelva a confirmar.
            $this->outbox->discardPending($appointment, MessageTemplateKey::Rescheduled);
            $appointment->update([
                'status' => AppointmentStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_via' => ConfirmationChannel::PatientLink,
            ]);

            $request->update([
                'status' => RescheduleRequestStatus::Approved,
                'approved_option_id' => $option->getKey(),
                'resolved_by_user_id' => $by->getKey(),
                'resolved_at' => now(),
            ]);

            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::RescheduleApproved);

            return $appointment;
        });
    }

    public function reject(RescheduleRequest $request, User $by, ?string $note = null): void
    {
        if ($request->status !== RescheduleRequestStatus::Pending) {
            return;
        }

        $this->appointments->assertCanManage($by, $request->appointment->doctor);

        $request->update([
            'status' => RescheduleRequestStatus::Rejected,
            'resolved_by_user_id' => $by->getKey(),
            'resolved_at' => now(),
            'response_note' => $note,
        ]);

        $this->outbox->queueForAppointment($request->appointment, MessageTemplateKey::RescheduleRejected);
    }
}
