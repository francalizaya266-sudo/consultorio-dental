<?php

namespace App\Policies;

use App\Models\LabOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Trabajos de laboratorio: recepción y administración llevan todos; cada doctor, los que él mandó. */
class LabOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas() || $user->doctorId() !== null;
    }

    public function view(User $user, LabOrder $order): bool
    {
        return $this->handles($user, $order);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LabOrder $order): bool
    {
        return $this->handles($user, $order);
    }

    /** Solo lo anotado por error: lo que ya se pagó queda, porque el gasto lo nombra. */
    public function delete(User $user, LabOrder $order): Response|bool
    {
        if (! $this->handles($user, $order)) {
            return false;
        }

        return $order->isPaid() ? Response::deny('Este trabajo ya se pagó. Anula primero el gasto en Caja.') : true;
    }

    private function handles(User $user, LabOrder $order): bool
    {
        return $user->managesAllAgendas() || ($user->doctorId() !== null && $order->doctor_id === $user->doctorId());
    }
}
