<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/** Fichas de pacientes: todo el equipo ve los datos de contacto; lo clínico tiene su propia política. */
class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Patient $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Patient $model): bool
    {
        return true;
    }

    public function delete(User $user, Patient $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Patient $model): bool
    {
        return $user->isAdmin();
    }
}
