<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

/** Proveedores: los manejan recepción y administración. No se borran: se desactivan. */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->managesAllAgendas();
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
