<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Setting;

/** Reglas de lo que el paciente puede cambiar por su cuenta (plazos que define el consultorio). */
class PatientChangePolicy
{
    public function rescheduleMode(Appointment $appointment, ?int $doctorId = null, ?int $treatmentId = null): RescheduleMode
    {
        $sameDoctor = $doctorId === null || $doctorId === $appointment->doctor_id;
        $sameTreatment = $treatmentId === null || $treatmentId === $appointment->treatment_id;
        $enoughTime = $appointment->hoursUntilStart() > (int) Setting::get('booking.self_reschedule_min_hours', 24);

        return $sameDoctor && $sameTreatment && $enoughTime && ! $appointment->fixed_date
            ? RescheduleMode::Automatic
            : RescheduleMode::NeedsApproval;
    }

    public function canChange(Appointment $appointment): bool
    {
        return $appointment->status->isChangeable() && $appointment->starts_at->isFuture();
    }

    public function canSelfCancel(Appointment $appointment): bool
    {
        return $this->canChange($appointment)
            && $appointment->hoursUntilStart() >= $this->selfCancelMinHours();
    }

    public function selfCancelMinHours(): int
    {
        return (int) Setting::get('booking.self_cancel_min_hours', 12);
    }
}
