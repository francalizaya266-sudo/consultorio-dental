<?php

namespace App\Policies;

use App\Enums\TreatmentPlanStatus;
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

    /**
     * La lista de presupuestos de todos para hacerles seguimiento: recepción y administración ven
     * todos (sin la pieza ni los datos clínicos); cada doctor, los que armó.
     */
    public function viewBudgets(User $user): bool
    {
        return $user->managesAllAgendas() || ($user->canSeeClinical() && $user->doctorId() !== null);
    }

    /**
     * La página de un presupuesto, con lo que incluye, cómo va su pago y su seguimiento: recepción y
     * administración abren cualquiera (sin la pieza ni los datos clínicos); el doctor, los de sus pacientes.
     */
    public function viewBudget(User $user, TreatmentPlan $plan): bool
    {
        return $user->managesAllAgendas() || $this->view($user, $plan);
    }

    /** El paciente dijo que no al presupuesto: lo cierra quien le hace seguimiento o el doctor que lo armó. */
    public function decline(User $user, TreatmentPlan $plan): bool
    {
        return $plan->status === TreatmentPlanStatus::Proposed
            && ($user->managesAllAgendas() || $this->update($user, $plan));
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
