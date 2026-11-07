<?php

namespace App\Policies;

use App\Models\DailyClosing;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Pagos: los registra recepción o administración. No se borran: se anulan. Un día cerrado solo lo cambia la administradora. */
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

    /** Anular: no en un día ya cerrado, salvo la administradora. */
    public function update(User $user, Payment $model): Response|bool
    {
        if (! $user->managesAllAgendas()) {
            return false;
        }

        return DailyClosing::locksFor($user, $model->paid_at) ? Response::deny(DailyClosing::LOCKED) : true;
    }

    public function delete(User $user, Payment $model): bool
    {
        return false;
    }
}
