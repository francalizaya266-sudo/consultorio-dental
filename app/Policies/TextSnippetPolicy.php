<?php

namespace App\Policies;

use App\Models\TextSnippet;
use App\Models\User;

/** Opciones rápidas: el administrador maneja las compartidas y cada doctor las suyas. */
class TextSnippetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->canSeeClinical();
    }

    public function view(User $user, TextSnippet $model): bool
    {
        return $this->manages($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->canSeeClinical();
    }

    public function update(User $user, TextSnippet $model): bool
    {
        return $this->manages($user, $model);
    }

    public function delete(User $user, TextSnippet $model): bool
    {
        return $this->manages($user, $model);
    }

    private function manages(User $user, TextSnippet $snippet): bool
    {
        return $snippet->isShared() ? $user->isAdmin() : $snippet->user_id === $user->getKey();
    }
}
