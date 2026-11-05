<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

/** Pedidos a proveedores: los arman y reciben recepción y administración. Un pedido no se borra: se anula. */
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    /** Recibir o anular, mientras falte que llegue algo. */
    public function update(User $user, PurchaseOrder $order): bool
    {
        return $user->managesAllAgendas() && $order->status->isOpen();
    }

    public function delete(User $user, PurchaseOrder $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
