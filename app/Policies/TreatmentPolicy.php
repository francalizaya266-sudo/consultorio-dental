<?php

namespace App\Policies;

use App\Models\Treatment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Tratamientos: qué se ofrece, a qué precio y dónde aparece lo decide el administrador. Los doctores abren cada
 * ficha para ajustar lo clínico (duración, seguimiento e indicaciones); el formulario les deja solo esos datos.
 */
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
        return $user->isAdmin();
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
