<?php

namespace App\Filament\Support;

use App\Exceptions\BookingException;
use App\Exceptions\ClinicalException;
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
        } catch (BookingException|ClinicalException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action?->halt();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    /**
     * Lo que la plantilla traía y no se copió a la receta porque no le conviene al paciente.
     *
     * @param  list<string>  $held
     */
    public static function heldMedications(array $held): void
    {
        if ($held === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('La plantilla traía un medicamento que no le conviene')
            ->body(implode("\n\n", $held)."\n\nSi aun así lo necesita, agrégalo a mano.")
            ->persistent()
            ->send();
    }
}
