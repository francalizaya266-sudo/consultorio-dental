<?php

namespace App\Console\Commands;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\CloudApiChannel;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('whatsapp:enviar {--limit=50 : Máximo de avisos por ejecución}')]
#[Description('Etapa 2: envía por la API oficial de WhatsApp los avisos que ya toca enviar')]
class SendDueWhatsAppMessages extends Command
{
    public function handle(WhatsAppOutbox $outbox, CloudApiChannel $channel): int
    {
        if ($outbox->channel() !== MessageChannel::CloudApi) {
            $this->line('La API de WhatsApp no está activada: recepción envía los avisos desde el panel.');

            return self::SUCCESS;
        }

        $messages = WhatsappMessage::query()
            ->due()
            ->where('status', MessageStatus::Pending)
            ->where('channel', MessageChannel::CloudApi)
            ->with(['account', 'appointment'])
            ->oldest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($messages as $message) {
            try {
                $prepared = $outbox->prepare($message);
                $providerId = $channel->send($message, $prepared['body'], $prepared['params']);
                $outbox->markSent($message, providerId: $providerId);
            } catch (Throwable $e) {
                report($e);
                $message->forceFill(['status' => MessageStatus::Failed, 'error' => $e->getMessage()])->save();
            }
        }

        $this->info("Avisos procesados: {$messages->count()}");

        return self::SUCCESS;
    }
}
