<?php

namespace App\Policies;

use App\Models\TreatmentPackage;
use App\Models\User;

/** Paquetes: los arman el administrador y los doctores. No se borran: se desactivan. */
class TreatmentPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    public function view(User $user, TreatmentPackage $model): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    public function update(User $user, TreatmentPackage $model): bool
    {
        return $user->isAdmin() || $user->isDoctor();
    }

    public function delete(User $user, TreatmentPackage $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
