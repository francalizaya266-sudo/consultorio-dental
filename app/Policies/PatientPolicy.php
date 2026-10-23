<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/** Fichas de pacientes: recepción y administración ven a todos; cada doctor, a sus pacientes. Lo clínico tiene su propia política. */
class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Patient $model): bool
    {
        return $this->treats($user, $model);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Patient $model): bool
    {
        return $this->treats($user, $model);
    }

    public function delete(User $user, Patient $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Patient $model): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Patient $model): bool
    {
        return false;
    }

    /** Historia clínica (Ley 29733): doctores de sus pacientes y el administrador solo si es odontólogo. */
    public function viewClinical(User $user, Patient $model): bool
    {
        return $user->canSeeClinical() && ($user->isAdmin() || $this->treats($user, $model));
    }

    /** Antecedentes y nuevas atenciones: un doctor (con perfil) que atiende al paciente. */
    public function updateClinical(User $user, Patient $model): bool
    {
        return $user->canSeeClinical() && $user->doctorId() !== null && $this->treats($user, $model);
    }

    /** Recepción y administración atienden a todos; el doctor, a quienes tienen cita o atención con él. */
    private function treats(User $user, Patient $patient): bool
    {
        return $user->managesAllAgendas()
            || $patient->appointments()->where('doctor_id', $user->doctorId())->exists()
            || $patient->consultations()->where('doctor_id', $user->doctorId())->exists();
    }
}
