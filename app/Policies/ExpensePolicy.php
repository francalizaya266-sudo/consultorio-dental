<?php

namespace App\Policies;

use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Gastos: los anota recepción o administración. No se borran: se anulan. Un día cerrado solo lo cambia la administradora. */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    /** Anular: no en un día ya cerrado, salvo la administradora. */
    public function update(User $user, Expense $expense): Response|bool
    {
        if (! $user->managesAllAgendas()) {
            return false;
        }

        return DailyClosing::locksFor($user, $expense->spent_at) ? Response::deny(DailyClosing::LOCKED) : true;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }
}
