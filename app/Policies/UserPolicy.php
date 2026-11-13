<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Usuarios del panel: solo el administrador. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /** Solo quien se registró por error y nunca entró: lo que ya hizo una persona lleva su nombre y se conserva. */
    public function delete(User $user, User $model): Response|bool
    {
        if (! $user->isAdmin() || $user->is($model)) {
            return false;
        }

        return $model->hasHistory() ? Response::deny('Esta persona ya entró al panel o tiene ficha de doctor. Para que no entre más, apaga "Puede entrar al panel": así se conserva lo que hizo.') : true;
    }
}
