<?php

namespace App\Policies;

use App\Models\Charge;
use App\Models\User;

/** Cargos: los arma el sistema y los revisa recepción o administración al cobrar. No se borran: se anulan. */
class ChargePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, Charge $model): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function update(User $user, Charge $model): bool
    {
        return $user->managesAllAgendas() && $model->voided_at === null;
    }

    public function delete(User $user, Charge $model): bool
    {
        return false;
    }
}
