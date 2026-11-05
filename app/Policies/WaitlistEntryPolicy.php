<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WaitlistEntry;

/** Lista de espera: la maneja quien maneja la agenda de todos. No se borra: se retira al paciente. */
class WaitlistEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, WaitlistEntry $entry): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    /** Mientras sigue esperando; lo agendado o retirado queda como registro. */
    public function update(User $user, WaitlistEntry $entry): bool
    {
        return $user->managesAllAgendas() && $entry->isActive();
    }

    public function delete(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }

    public function forceDelete(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }
}
