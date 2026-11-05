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

        Notification::make()
            ->title($title)
            ->body($body)
            ->actions($link ? [Action::make('open')->label($link['label'])->url($link['url'])->markAsRead()] : [])
            ->sendToDatabase($recipients);
    }

    public function toUser(?User $user, string $title, ?string $body = null): void
    {
        if ($user === null || ! $user->is_active) {
            return;
        }

        Notification::make()->title($title)->body($body)->sendToDatabase($user);
    }
}
