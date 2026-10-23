<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/** Pagos: los registra recepción o administración. No se borran: se anulan. */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, Payment $model): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function update(User $user, Payment $model): bool
    {
        return $user->managesAllAgendas();
    }

    public function delete(User $user, Payment $model): bool
    {
        return false;
    }
}
