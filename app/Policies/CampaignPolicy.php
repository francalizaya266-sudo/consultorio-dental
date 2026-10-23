<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

/** Campañas: solo el administrador. */
class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Campaign $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Campaign $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Campaign $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Campaign $model): bool
    {
        return $user->isAdmin();
    }
}
