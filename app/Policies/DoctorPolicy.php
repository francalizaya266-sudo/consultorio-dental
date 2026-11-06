<?php

namespace App\Policies;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Perfiles de doctores: los configura el administrador. */
class DoctorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Doctor $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Doctor $model): bool
    {
        return $user->isAdmin();
    }

    /** Solo el administrador, y solo lo registrado por error: lo que ya tiene historia se conserva. */
    public function delete(User $user, Doctor $model): Response|bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $model->hasHistory() ? Response::deny('Este doctor ya tiene citas o historias. Para que deje de salir en la agenda y en la web, apaga "Activo" en su ficha.') : true;
    }

    public function restore(User $user, Doctor $model): bool
    {
        return $user->isAdmin();
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin();
    }
}
