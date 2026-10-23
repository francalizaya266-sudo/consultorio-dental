<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappMessage;

/** Avisos de WhatsApp: recepción y administración todos; el doctor los de sus citas. */
class WhatsappMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WhatsappMessage $model): bool
    {
        return $this->ownsMessage($user, $model);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WhatsappMessage $model): bool
    {
        return $this->ownsMessage($user, $model);
    }

    public function delete(User $user, WhatsappMessage $model): bool
    {
        return false;
    }

    private function ownsMessage(User $user, WhatsappMessage $message): bool
    {
        return $user->managesAllAgendas()
            || ($message->appointment !== null && $message->appointment->doctor_id === $user->doctorId());
    }
}
