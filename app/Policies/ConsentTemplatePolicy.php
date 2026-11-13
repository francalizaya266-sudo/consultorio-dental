<?php

namespace App\Policies;

use App\Models\ConsentTemplate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    /** Solo el administrador, y solo lo registrado por error: un texto ya firmado se conserva. */
    public function delete(User $user, ConsentTemplate $template): Response|bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $template->hasHistory() ? Response::deny('Con este texto ya se firmaron consentimientos. Para que no se proponga más, apaga "En uso": lo firmado no cambia.') : true;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
