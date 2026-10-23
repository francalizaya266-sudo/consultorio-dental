<?php

namespace App\Filament\Support;

use App\Exceptions\BookingException;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/** Ejecuta una acción del panel y muestra el resultado (o el motivo del error) en español simple. */
final class Notify
{
    public static function run(Closure $callback, string $success, ?Action $action = null): void
    {
        try {
            $callback();
        } catch (BookingException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action?->halt();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
