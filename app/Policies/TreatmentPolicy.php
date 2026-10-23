<?php

namespace App\Policies;

use App\Models\Treatment;
use App\Models\User;

/** Tratamientos, precios y duraciones: los configura el administrador. */
class TreatmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Treatment $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Treatment $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Treatment $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Treatment $model): bool
    {
        return $user->isAdmin();
    }
}
