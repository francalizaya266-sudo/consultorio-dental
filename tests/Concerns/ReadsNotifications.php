<?php

namespace Tests\Concerns;

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Collection;

/** Para revisar el texto y los botones del aviso que quedó en pantalla, no solo su título. */
trait ReadsNotifications
{
    /**
     * @return array{title: string, body: string|null, duration: int|string, actions: list<array{label: string, url: string|null}>}
     */
    protected function lastNotification(): array
    {
        return $this->shownNotifications()->last();
    }

    /**
     * Todos los avisos que quedaron en pantalla, en orden: sirve cuando detrás del que interesa sale otro
     * (por ejemplo, el "Creado" de un formulario).
     *
     * @return Collection<int, array{title: string, body: string|null, duration: int|string, actions: list<array{label: string, url: string|null}>}>
     */
    protected function shownNotifications(): Collection
    {
        app(NotificationMailbox::class)->flush();
        $shown = new Notifications;
        $shown->mount();
        $shown->pullNotificationsFromSession();

        return $shown->notifications->values()->map->toArray()->toBase();
    }
}
