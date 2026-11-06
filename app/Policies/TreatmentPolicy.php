<?php

namespace App\Policies;

use App\Models\Treatment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Tratamientos, precios y duraciones: los configuran el administrador y los doctores; quitarlos u ordenarlos, solo el administrador. */
class TreatmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    public function view(User $user, Treatment $model): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    public function update(User $user, Treatment $model): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    /** Solo el administrador, y solo lo registrado por error: lo que ya tiene historia se conserva. */
    public function delete(User $user, Treatment $model): Response|bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $model->hasHistory() ? Response::deny('Este tratamiento ya tiene citas o cobros. Para que no se ofrezca más, apaga "Activo": así se conserva su historial.') : true;
    }

    public function restore(User $user, Treatment $model): bool
    {
        return $user->isAdmin();
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin();
    }
}
