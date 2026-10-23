<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\StaffAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citas:marcar-sin-confirmar')]
#[Description('Marca como "sin confirmar" las citas de mañana que el paciente aún no confirmó, para que recepción llame')]
class FlagUnconfirmedAppointments extends Command
{
    public function handle(StaffAlerts $alerts): int
    {
        $tomorrow = now()->addDay();

        $flagged = Appointment::query()
            ->where('status', AppointmentStatus::Pending)
            ->whereNull('unconfirmed_flagged_at')
            ->whereBetween('starts_at', [$tomorrow->copy()->startOfDay(), $tomorrow->copy()->endOfDay()])
            ->update(['unconfirmed_flagged_at' => now()]);

        if ($flagged > 0) {
            $alerts->toReception(
                "{$flagged} cita(s) de mañana sin confirmar",
                'Llama a estos pacientes para confirmar su asistencia. Están marcadas en la agenda.',
            );
        }

        $this->info("Citas sin confirmar: {$flagged}");

        return self::SUCCESS;
    }
}
