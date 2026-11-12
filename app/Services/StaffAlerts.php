<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** Alertas en la campana del panel para recepción (y administración). */
class StaffAlerts
{
    /**
     * @param  array{label: string, url: string}|null  $link  Botón para ir directo a resolverlo.
     */
    public function toReception(string $title, ?string $body = null, ?User $except = null, ?array $link = null): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Reception, UserRole::Admin])
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $this->deliver($recipients, Notification::make()
            ->title($title)
            ->body($body)
            ->actions($link ? [Action::make('open')->label($link['label'])->url($link['url'])->markAsRead()] : []));
    }

    public function toUser(?User $user, string $title, ?string $body = null): void
    {
        if ($user === null || ! $user->is_active) {
            return;
        }

        $this->deliver([$user], Notification::make()->title($title)->body($body));
    }

    /**
     * Al doctor: su paciente ya está en sala. Si lo marcó él mismo, no hace falta decírselo. El aviso guarda de
     * qué cita es, para darlo por leído cuando el paciente pasa a consulta.
     */
    public function patientArrived(Appointment $appointment, User $by): void
    {
        $doctorUser = $appointment->doctor->user;

        if ($doctorUser === null || ! $doctorUser->is_active || $doctorUser->is($by)) {
            return;
        }

        $this->deliver([$doctorUser], Notification::make()
            ->title("Llegó {$appointment->patient->full_name}")
            ->body("Te espera en sala. Cita de las {$appointment->starts_at->format('H:i')} · {$appointment->treatment->name}.")
            ->viewData(['arrival' => $appointment->getKey()]));
    }

    /** El paciente ya no espera (pasó a consulta o la cita se cerró): el aviso de que llegó queda como leído. */
    public function arrivalAttended(Appointment $appointment): void
    {
        $appointment->doctor->user?->unreadNotifications()
            ->where('data->viewData->arrival', $appointment->getKey())
            ->update(['read_at' => now()]);
    }

    /**
     * Queda en la campana al instante. Antes se encolaba y solo aparecía cuando la tarea programada del hosting
     * procesaba la cola: si esa tarea no corría, la alerta no llegaba nunca.
     *
     * @param  iterable<User>  $recipients
     */
    private function deliver(iterable $recipients, Notification $notification): void
    {
        foreach ($recipients as $recipient) {
            $recipient->notifyNow($notification->toDatabase());
        }
    }
}
