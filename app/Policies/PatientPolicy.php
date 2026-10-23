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

    /** Recepción y administración atienden a todos; el doctor, a quienes tienen cita con él. */
    private function treats(User $user, Patient $patient): bool
    {
        return $user->managesAllAgendas()
            || $patient->appointments()->where('doctor_id', $user->doctorId())->exists();
    }
}
