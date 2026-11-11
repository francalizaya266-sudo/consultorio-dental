<?php

namespace Tests\Concerns;

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;

/** Para revisar el texto y los botones del aviso que quedó en pantalla, no solo su título. */
trait ReadsNotifications
{
    /**
     * @return array{title: string, body: string|null, duration: int|string, actions: list<array{label: string, url: string|null}>}
     */
    protected function lastNotification(): array
    {
        app(NotificationMailbox::class)->flush();
        $shown = new Notifications;
        $shown->mount();
        $shown->pullNotificationsFromSession();

        return $shown->notifications->last()->toArray();
    }
}
