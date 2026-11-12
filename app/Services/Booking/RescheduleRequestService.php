<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\ConfirmationChannel;
use App\Enums\MessageTemplateKey;
use App\Enums\RequestedBy;
use App\Enums\RescheduleRequestStatus;
use App\Exceptions\BookingException;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
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
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Nivel 2 de cambios de horario: el paciente propone hasta 3 horarios y
 * recepción o el doctor aprueba uno con un clic. Mientras tanto, la cita
 * original se mantiene.
 */
class RescheduleRequestService
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly AvailabilityService $availability,
        private readonly PatientChangePolicy $policy,
        private readonly WhatsAppOutbox $outbox,
        private readonly StaffAlerts $alerts,
    ) {}

    /**
     * @param  list<CarbonInterface>  $proposedStarts
     */
    public function requestByPatient(
        Appointment $appointment,
        array $proposedStarts,
        ?string $reason = null,
        ?Doctor $doctor = null,
        ?Treatment $treatment = null,
    ): RescheduleRequest {
        if (! $this->policy->canChange($appointment)) {
            throw BookingException::notChangeable();
        }

        $max = (int) Setting::get('booking.max_reschedule_options', 3);

        if (count($proposedStarts) === 0 || count($proposedStarts) > $max) {
            throw BookingException::tooManyOptions($max);
        }

        $doctor ??= $appointment->doctor;
        $treatment ??= $appointment->treatment;
        $duration = $doctor->durationFor($treatment);
        $buffer = $treatment->bufferMinutes();

        foreach ($proposedStarts as $start) {
            if (! $this->availability->forPatient($appointment->patient_id)->isFree($doctor, $start, $duration, $buffer, $appointment->getKey())) {
                throw BookingException::slotTaken();
            }
        }

        return DB::transaction(function () use ($appointment, $proposedStarts, $reason, $doctor, $treatment, $duration) {
            // Una sola solicitud viva por cita: la nueva reemplaza a la anterior.
            $appointment->rescheduleRequests()
                ->where('status', RescheduleRequestStatus::Pending)
                ->update(['status' => RescheduleRequestStatus::Withdrawn]);

            $request = $appointment->rescheduleRequests()->create([
                'requested_by' => RequestedBy::Patient,
                'status' => RescheduleRequestStatus::Pending,
                'requested_doctor_id' => $doctor->isNot($appointment->doctor) ? $doctor->getKey() : null,
                'requested_treatment_id' => $treatment->isNot($appointment->treatment) ? $treatment->getKey() : null,
                'reason' => $reason,
            ]);

            foreach ($proposedStarts as $start) {
                $request->options()->create([
                    'doctor_id' => $doctor->getKey(),
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes($duration),
                ]);
            }

            $appointment->logEvent('reschedule_requested', 'patient', ['notes' => $reason]);

            $this->alerts->toReception(
                'Solicitud de cambio de horario',
                "{$appointment->patient->full_name} propone ".(count($proposedStarts) === 1 ? 'un horario' : count($proposedStarts).' horarios').' para su cita. Apruébala con un clic.',
                link: ['label' => 'Ver la solicitud', 'url' => RescheduleRequestResource::getUrl()],
            );

            if ($doctorUser = $appointment->doctor->user) {
                $this->alerts->toUser($doctorUser, 'Solicitud de cambio de horario', "{$appointment->patient->full_name} pidió mover su cita.");
            }

            return $request;
        });
    }

    public function approve(RescheduleRequest $request, RescheduleRequestOption $option, User $by): Appointment
    {
        if ($request->status !== RescheduleRequestStatus::Pending || $option->reschedule_request_id !== $request->getKey()) {
            throw BookingException::notChangeable();
        }

        $option->loadMissing('doctor');

        return DB::transaction(function () use ($request, $option, $by) {
            // Queda resuelta antes de mover la cita: al moverla, las que siguen pendientes se cierran solas.
            $request->update([
                'status' => RescheduleRequestStatus::Approved,
                'approved_option_id' => $option->getKey(),
                'resolved_by_user_id' => $by->getKey(),
                'resolved_at' => now(),
            ]);

            $appointment = $this->withRequestedTreatment($request);
            $appointment = $this->appointments->rescheduleByStaff($appointment, $option->starts_at, $by, $option->doctor);

            // El paciente eligió este horario: no hace falta que vuelva a confirmar.
            $appointment->update([
                'status' => AppointmentStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_via' => ConfirmationChannel::PatientLink,
            ]);

            $this->outbox->discardPending($appointment, MessageTemplateKey::Rescheduled);
            $this->outbox->queueForAppointment($appointment, MessageTemplateKey::RescheduleApproved);

            return $appointment;
        });
    }

    /**
     * Ninguno de los horarios que propuso el paciente sirve (o ya se ocuparon): se le da otro. La cita se mueve
     * ahí y la solicitud queda resuelta. Si no se acordó con el paciente, queda listo el aviso para que confirme.
     */
    public function offerAnotherTime(RescheduleRequest $request, CarbonInterface $startsAt, User $by, ?Doctor $doctor = null, bool $agreed = false): Appointment
    {
        if ($request->status !== RescheduleRequestStatus::Pending) {
            throw BookingException::notChangeable();
        }

        return DB::transaction(function () use ($request, $startsAt, $by, $doctor, $agreed) {
            $request->update([
                'status' => RescheduleRequestStatus::Approved,
                'resolved_by_user_id' => $by->getKey(),
                'resolved_at' => now(),
                'response_note' => 'Se le dio otro horario: '.$startsAt->translatedFormat('D d/m H:i').'.',
            ]);

            return $this->appointments->rescheduleByStaff($this->withRequestedTreatment($request), $startsAt, $by, $doctor, $agreed);
        });
    }

    /**
     * Cierra las solicitudes pendientes de citas cuya hora ya pasó o que ya no se pueden mover (canceladas,
     * atendidas). Devuelve cuántas cerró.
     */
    public function expireStale(): int
    {
        return RescheduleRequest::query()
            ->where('status', RescheduleRequestStatus::Pending)
            ->whereHas('appointment', fn (Builder $appointment) => $appointment->where(fn (Builder $stale) => $stale
                ->where('starts_at', '<', now())
                ->orWhereNotIn('status', AppointmentStatus::changeable())))
            ->with('appointment')
            ->get()
            ->sum(fn (RescheduleRequest $request) => $this->appointments->closeRescheduleRequests($request->appointment, 'La cita ya pasó o se cerró.'));
    }

    /** La cita de la solicitud, ya con el tratamiento que el paciente pidió (si pidió otro). */
    private function withRequestedTreatment(RescheduleRequest $request): Appointment
    {
        $appointment = $request->appointment;

        if ($request->requested_treatment_id) {
            $appointment->update(['treatment_id' => $request->requested_treatment_id]);
            // Su duración sale del tratamiento nuevo, no del que ya estaba cargado.
            $appointment->unsetRelation('treatment');
        }

        return $appointment;
    }

    public function reject(RescheduleRequest $request, User $by, ?string $note = null): void
    {
        $this->appointments->assertCanManage($by, $request->appointment->doctor);

        $request->update([
            'status' => RescheduleRequestStatus::Rejected,
            'resolved_by_user_id' => $by->getKey(),
            'resolved_at' => now(),
            'response_note' => $note,
        ]);

        $request->appointment->logEvent('reschedule_rejected', 'staff', ['notes' => $note]);
        $this->outbox->queueForAppointment($request->appointment, MessageTemplateKey::RescheduleRejected);
    }
}
