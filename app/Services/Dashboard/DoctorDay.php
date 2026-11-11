<?php

namespace App\Services\Dashboard;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Consultation;
use Illuminate\Database\Eloquent\Collection;

/** Lo que el doctor necesita al entrar: a quién atiende ahora y lo que dejó sin terminar. */
final class DoctorDay
{
    public function __construct(public readonly int $doctorId) {}

    /**
     * El siguiente paciente de hoy: quien ya está en sala (el que llegó primero) o, si nadie llegó,
     * la próxima cita por hora. No cuenta lo que ya se está atendiendo, ni a quien no llegó y ya pasó
     * su hora: eso es una inasistencia por marcar, no un paciente que viene.
     */
    public function next(): ?Appointment
    {
        return Appointment::query()
            ->where('doctor_id', $this->doctorId)
            ->whereDate('starts_at', today())
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->waitingNow())
                ->orWhere(fn ($query) => $query
                    ->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::Pending])
                    ->where('ends_at', '>', now())))
            ->with(['patient.medicalBackground', 'treatment', 'planItem'])
            ->orderByRaw('case when status = ? then 0 else 1 end', [AppointmentStatus::CheckedIn->value])
            ->orderByRaw('case when status = ? then checked_in_at end', [AppointmentStatus::CheckedIn->value])
            ->orderBy('starts_at')
            ->first();
    }

    /** Cuántos pacientes suyos esperan en sala ahora. */
    public function waiting(): int
    {
        return Appointment::query()
            ->where('doctor_id', $this->doctorId)
            ->waitingNow()
            ->count();
    }

    /** La última vez que el paciente se atendió (con cualquier doctor), para saber qué se hizo. */
    public function lastVisit(Appointment $appointment): ?Appointment
    {
        return Appointment::query()
            ->where('patient_id', $appointment->patient_id)
            ->where('status', AppointmentStatus::Completed)
            ->where('starts_at', '<', $appointment->starts_at)
            ->with('treatment')
            ->latest('starts_at')
            ->first();
    }

    /** @return Collection<int, Consultation> Sus atenciones abiertas, la más antigua primero. */
    public function openConsultations(): Collection
    {
        return Consultation::query()
            ->where('doctor_id', $this->doctorId)
            ->whereNull('closed_at')
            ->with(['patient', 'appointment.treatment'])
            ->oldest('attended_at')
            ->get();
    }
}
