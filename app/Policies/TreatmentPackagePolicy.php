<?php

namespace App\Policies;

use App\Models\TreatmentPackage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Paquetes: los arman el administrador y los doctores. Borrar, solo el administrador y solo lo que nunca se usó. */
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

    /** Solo el administrador, y solo lo registrado por error: lo que ya está en un plan se conserva. */
    public function delete(User $user, TreatmentPackage $model): Response|bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $model->hasHistory() ? Response::deny('Este paquete ya está en un plan o en una campaña. Para que no se ofrezca más, apaga "Se ofrece": así los planes que lo usan no cambian.') : true;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
