<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;
use App\Services\Clinical\ConsultationService;

/**
 * Lo clínico solo lo ven los doctores (de sus pacientes) y el administrador si es odontólogo.
 * Cada atención la escribe solo el doctor que atendió, mientras esté abierta. Nada se borra.
 */
class ConsultationPolicy
{
    public function __construct(private readonly PatientPolicy $patients) {}

    public function viewAny(User $user): bool
    {
        return $user->canSeeClinical();
    }

    public function view(User $user, Consultation $consultation): bool
    {
        return $this->patients->viewClinical($user, $consultation->patient);
    }

    public function create(User $user): bool
    {
        return $user->canSeeClinical() && $user->doctorId() !== null;
    }

    public function update(User $user, Consultation $consultation): bool
    {
        return $user->canSeeClinical()
            && $user->doctorId() === $consultation->doctor_id
            && $consultation->closed_at === null;
    }

    public function reopen(User $user, Consultation $consultation): bool
    {
        return $user->canSeeClinical()
            && $user->doctorId() === $consultation->doctor_id
            && app(ConsultationService::class)->canStillReopen($consultation);
    }

    public function delete(User $user, Consultation $consultation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Consultation $consultation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Consultation $consultation): bool
    {
        return false;
    }
}
