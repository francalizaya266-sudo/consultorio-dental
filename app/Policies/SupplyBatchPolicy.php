<?php

namespace App\Policies;

use App\Models\SupplyBatch;
use App\Models\User;

/** Los lotes los ve quien ve el inventario. Entran y salen solo con movimientos. */
class SupplyBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas() || $user->isDoctor();
    }

    public function view(User $user, SupplyBatch $batch): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SupplyBatch $batch): bool
    {
        return false;
    }

    public function delete(User $user, SupplyBatch $batch): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
