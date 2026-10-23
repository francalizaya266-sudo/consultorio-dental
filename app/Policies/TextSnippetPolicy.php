<?php

namespace App\Policies;

use App\Enums\SnippetStatus;
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

    /** El administrador convierte en compartida una opción que un doctor propuso. */
    public function approve(User $user, TextSnippet $model): bool
    {
        return $user->isAdmin() && $model->status === SnippetStatus::Proposed;
    }

    private function manages(User $user, TextSnippet $snippet): bool
    {
        return $snippet->isShared() ? $user->isAdmin() : $snippet->user_id === $user->getKey();
    }
}
