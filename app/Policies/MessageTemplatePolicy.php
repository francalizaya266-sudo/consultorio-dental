<?php

namespace App\Policies;

use App\Models\MessageTemplate;
use App\Models\User;

/** Textos de los avisos de WhatsApp: solo el administrador. */
class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, MessageTemplate $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MessageTemplate $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, MessageTemplate $model): bool
    {
        return false;
    }
}
