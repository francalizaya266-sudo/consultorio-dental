<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

/** Citas: administración y recepción manejan todas; cada doctor solo las suyas. No se borran: se cancelan. */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $model): bool
    {
        return $this->ownsAgenda($user, $model);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Appointment $model): bool
    {
        return $this->ownsAgenda($user, $model);
    }

    public function delete(User $user, Appointment $model): bool
    {
        return false;
    }

    private function ownsAgenda(User $user, Appointment $appointment): bool
    {
        return $user->managesAllAgendas() || $appointment->doctor_id === $user->doctorId();
    }
}
