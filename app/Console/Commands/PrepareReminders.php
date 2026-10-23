<?php

namespace App\Console\Commands;

use App\Enums\MessageTemplateKey;
use App\Models\Appointment;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citas:preparar-recordatorios')]
#[Description('Prepara en la bandeja de WhatsApp los recordatorios de las citas de mañana')]
class PrepareReminders extends Command
{
    public function handle(WhatsAppOutbox $outbox): int
    {
        $tomorrow = now()->addDay();

        $appointments = Appointment::query()
            ->upcoming()
            ->whereBetween('starts_at', [$tomorrow->copy()->startOfDay(), $tomorrow->copy()->endOfDay()])
            ->whereNotIn('id', WhatsappMessage::query()
                ->select('appointment_id')
                ->whereNotNull('appointment_id')
                ->where('template_key', MessageTemplateKey::Reminder))
            ->with(['patient.account', 'doctor', 'treatment'])
            ->get();

        $appointments->each(fn (Appointment $appointment) => $outbox->queueForAppointment($appointment, MessageTemplateKey::Reminder));

        $this->info("Recordatorios preparados: {$appointments->count()}");

        return self::SUCCESS;
    }
}
