<?php

namespace App\Policies;

use App\Models\TreatmentPlan;
use App\Models\User;

/** Planes de tratamiento: parte de la historia clínica. Los arma y cambia un doctor del paciente; nada se borra. */
class TreatmentPlanPolicy
{
    public function __construct(private readonly PatientPolicy $patients) {}

    public function viewAny(User $user): bool
    {
        return $user->canSeeClinical();
    }

    public function view(User $user, TreatmentPlan $plan): bool
    {
        return $this->patients->viewClinical($user, $plan->patient);
    }

    public function create(User $user): bool
    {
        return $user->canSeeClinical() && $user->doctorId() !== null;
    }

    /** Mientras esté vigente; uno terminado o cancelado queda como registro. */
    public function update(User $user, TreatmentPlan $plan): bool
    {
        return $plan->isOpen() && $this->patients->updateClinical($user, $plan->patient);
    }

    public function delete(User $user, TreatmentPlan $plan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, TreatmentPlan $plan): bool
    {
        return false;
    }

    public function forceDelete(User $user, TreatmentPlan $plan): bool
    {
        return false;
    }
}
