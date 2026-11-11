<?php

namespace App\Policies;

use App\Models\DeskNote;
use App\Models\User;

/** Pendientes de recepción: los anota y los marca recepción o administración. No se borran: se marcan como hechos. */
class DeskNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function update(User $user, DeskNote $note): bool
    {
        return $user->managesAllAgendas();
    }

    public function delete(User $user, DeskNote $note): bool
    {
        return false;
    }
}
