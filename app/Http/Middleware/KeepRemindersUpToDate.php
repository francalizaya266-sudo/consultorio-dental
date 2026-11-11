<?php

namespace App\Http\Middleware;

use App\Services\Booking\AppointmentReminders;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras alguien usa el panel, los recordatorios se ponen al día solos (a lo más, una vez cada diez minutos).
 * Así no dependen solo de la tarea programada del hosting: si un día no corre, igual aparecen en Avisos.
 */
class KeepRemindersUpToDate
{
    private const EVERY_MINUTES = 10;

    public function __construct(private readonly AppointmentReminders $reminders) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Cache::add('reminders.caught-up', true, now()->addMinutes(self::EVERY_MINUTES))) {
            rescue(fn () => $this->reminders->catchUp());
        }

        return $next($request);
    }
}
