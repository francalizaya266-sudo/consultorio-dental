<?php

use Illuminate\Support\Facades\Schedule;

/*
| El hosting compartido ejecuta un solo cron cada minuto:
| * * * * * php /ruta/al/proyecto/artisan schedule:run >> /dev/null 2>&1
*/

Schedule::command('citas:preparar-recordatorios')
    ->dailyAt(config('consultorio.defaults.reminders.day_before_at', '09:00'))
    ->withoutOverlapping();

Schedule::command('citas:marcar-sin-confirmar')
    ->dailyAt(config('consultorio.defaults.reminders.flag_unconfirmed_at', '16:00'))
    ->withoutOverlapping();

Schedule::command('whatsapp:enviar')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Sin servidor de colas: el mismo cron procesa los trabajos pendientes.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('model:prune')->daily();
