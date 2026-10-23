<?php

namespace App\Policies;

use App\Models\ConsentTemplate;
use App\Models\User;

/** Textos de consentimiento: los redacta el administrador; los doctores los consultan. */
class ConsentTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->doctorId() !== null;
    }

    public function view(User $user, ConsentTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ConsentTemplate $template): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ConsentTemplate $template): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
