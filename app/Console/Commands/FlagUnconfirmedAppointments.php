<?php

namespace App\Console\Commands;

use App\Services\Booking\AppointmentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citas:marcar-sin-confirmar')]
#[Description('Marca como "sin confirmar" las citas que el día anterior en la tarde siguen sin confirmar, para que recepción llame')]
class FlagUnconfirmedAppointments extends Command
{
    public function handle(AppointmentReminders $reminders): int
    {
        $this->info('Citas sin confirmar: '.$reminders->flagUnconfirmed());

        return self::SUCCESS;
    }
}
