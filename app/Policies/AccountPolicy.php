<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/** Celulares y familias: administración y recepción (incluye bloquear números). */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, Account $model): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function update(User $user, Account $model): bool
    {
        return $user->managesAllAgendas();
    }

    public function delete(User $user, Account $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Account $model): bool
    {
        return $user->isAdmin();
    }

    public function block(User $user, Account $model): bool
    {
        return $user->managesAllAgendas();
    }
}
