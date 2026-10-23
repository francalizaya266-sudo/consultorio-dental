<?php

namespace App\Policies;

use App\Models\ScheduleBlock;
use App\Models\User;

/** Bloqueos de horario: recepción y administración en todas las agendas; cada doctor en la suya. */
class ScheduleBlockPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ScheduleBlock $model): bool
    {
        return $this->ownsAgenda($user, $model);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ScheduleBlock $model): bool
    {
        return $this->ownsAgenda($user, $model);
    }

    public function delete(User $user, ScheduleBlock $model): bool
    {
        return $this->ownsAgenda($user, $model);
    }

    private function ownsAgenda(User $user, ScheduleBlock $block): bool
    {
        return $user->managesAllAgendas() || ($block->doctor_id !== null && $block->doctor_id === $user->doctorId());
    }
}
