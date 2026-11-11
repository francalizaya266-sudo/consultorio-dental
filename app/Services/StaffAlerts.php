<?php

namespace App\Services;

use App\Enums\UserRole;
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
