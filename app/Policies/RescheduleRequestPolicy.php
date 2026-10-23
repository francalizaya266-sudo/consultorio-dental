<?php

namespace App\Policies;

use App\Models\RescheduleRequest;
use App\Models\User;

/** Solicitudes de cambio: las aprueba recepción, administración o el doctor de la cita. */
class RescheduleRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RescheduleRequest $model): bool
    {
        return $this->ownsRequest($user, $model);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RescheduleRequest $model): bool
    {
        return $this->ownsRequest($user, $model);
    }

    public function delete(User $user, RescheduleRequest $model): bool
    {
        return false;
    }

    private function ownsRequest(User $user, RescheduleRequest $request): bool
    {
        return $user->managesAllAgendas() || $request->appointment->doctor_id === $user->doctorId();
    }
}
