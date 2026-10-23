<?php

namespace App\Policies;

use App\Models\Consent;
use App\Models\User;

/** Consentimientos firmados: no se editan ni se borran; si el paciente lo retira, se revoca. */
class ConsentPolicy
{
    public function __construct(private readonly PatientPolicy $patients) {}

    public function viewAny(User $user): bool
    {
        return $user->canSeeClinical();
    }

    public function view(User $user, Consent $consent): bool
    {
        return $this->patients->viewClinical($user, $consent->patient);
    }

    public function create(User $user): bool
    {
        return $user->canSeeClinical() && $user->doctorId() !== null;
    }

    public function update(User $user, Consent $consent): bool
    {
        return false;
    }

    public function revoke(User $user, Consent $consent): bool
    {
        return $consent->isActive() && $this->patients->updateClinical($user, $consent->patient);
    }

    public function delete(User $user, Consent $consent): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
