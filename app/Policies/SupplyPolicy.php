<?php

namespace App\Policies;

use App\Models\Supply;
use App\Models\User;

/**
 * Inventario: recepción y administración cargan las compras y cuentan; el doctor ve lo que hay.
 * Los costos los ve solo administración. Un material no se borra: se desactiva (tiene historia).
 */
class SupplyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas() || $user->isDoctor();
    }

    public function view(User $user, Supply $supply): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function update(User $user, Supply $supply): bool
    {
        return $user->managesAllAgendas();
    }

    /** Entradas, bajas y el conteo del mes. */
    public function moveStock(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function viewCosts(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Supply $supply): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
