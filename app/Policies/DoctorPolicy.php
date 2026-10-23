<?php

namespace App\Policies;

use App\Models\Doctor;
use App\Models\User;

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

    public function delete(User $user, Doctor $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Doctor $model): bool
    {
        return $user->isAdmin();
    }
}
