<?php

namespace App\Console\Commands;

use App\Services\Booking\AppointmentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citas:preparar-recordatorios')]
#[Description('Prepara en la bandeja de WhatsApp los recordatorios de las citas a las que ya les toca (el día anterior)')]
class PrepareReminders extends Command
{
    public function handle(AppointmentReminders $reminders): int
    {
        $expired = $reminders->expireStale();
        $prepared = $reminders->prepareReminders();

        $this->info("Recordatorios preparados: {$prepared}");
        $this->info("Avisos de citas pasadas retirados: {$expired}");

        return self::SUCCESS;
    }
}
