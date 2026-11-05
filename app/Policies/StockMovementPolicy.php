<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

/**
 * La historia del inventario la ve quien ve el inventario. Nadie la edita ni la borra:
 * los movimientos los registra el sistema y un error se corrige con otro movimiento.
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas() || $user->isDoctor();
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
