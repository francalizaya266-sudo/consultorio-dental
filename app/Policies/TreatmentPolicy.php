<?php

namespace App\Policies;

use App\Models\Treatment;
use App\Models\User;

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

    public function delete(User $user, Treatment $model): bool
    {
        return $user->isAdmin();
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
