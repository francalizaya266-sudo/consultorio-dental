<?php

namespace App\Services\WhatsApp;

use App\Enums\MagicLinkPurpose;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Access\MagicLinkService;
use App\Support\Phone;
use Carbon\CarbonInterface;

/**
 * Bandeja de avisos de WhatsApp.
 *
 * Etapa 1 (ahora): el panel prepara el mensaje y lo abre en el WhatsApp del
 * consultorio ya escrito; recepción o el doctor solo presiona Enviar.
 * Etapa 2: con la API oficial, el comando whatsapp:enviar los manda solos.
 */
class WhatsAppOutbox
{
    public function __construct(private readonly MagicLinkService $links) {}

    public function queueForAppointment(
        Appointment $appointment,
        MessageTemplateKey $key,
        ?CarbonInterface $dueAt = null,
        array $extra = [],
    ): ?WhatsappMessage {
        $appointment->loadMissing('patient.account', 'doctor', 'treatment');
        $account = $appointment->patient->account;

        if ($account === null) {
            return null; // paciente sin celular registrado
        }

        // Un solo aviso pendiente por tipo y cita (si la mueven dos veces, vale el último).
        $this->discardPending($appointment, $key);

        return WhatsappMessage::create([
            'account_id' => $account->getKey(),
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->getKey(),
            'template_key' => $key,
            'phone' => $account->phone,
            'params' => $this->appointmentParams($appointment) + $extra,
            'status' => MessageStatus::Pending,
            'channel' => $this->channel(),
            'due_at' => $dueAt,
        ]);
    }

    public function queueForAccount(Account $account, MessageTemplateKey $key, array $params = []): WhatsappMessage
    {
        return WhatsappMessage::create([
            'account_id' => $account->getKey(),
            'template_key' => $key,
            'phone' => $account->phone,
            'params' => $params + ['consultorio' => Setting::get('clinic.name')],
            'status' => MessageStatus::Pending,
            'channel' => $this->channel(),
        ]);
    }

    /** Descarta avisos pendientes de una cita (por ejemplo, al cancelarla o moverla). */
    public function discardPending(Appointment $appointment, ?MessageTemplateKey $key = null): void
    {
        WhatsappMessage::query()
            ->where('appointment_id', $appointment->getKey())
            ->when($key, fn ($q) => $q->where('template_key', $key))
            ->pending()
            ->update(['status' => MessageStatus::Cancelled]);
    }

    /** Arma el texto final. Si lleva {enlace}, se genera un enlace personal nuevo. */
    public function render(WhatsappMessage $message, ?User $by = null): string
    {
        return $this->prepare($message, $by)['body'];
    }

    /** @return array{body: string, params: array<string, string>} */
    public function prepare(WhatsappMessage $message, ?User $by = null): array
    {
        $template = MessageTemplate::query()->where('key', $message->template_key)->where('is_active', true)->first();
        $body = $template?->body ?? $message->template_key->defaultBody();

        $params = $message->params ?? [];

        if (str_contains($body, '{enlace}') && $message->account) {
            $appointment = $message->appointment;
            $params['enlace'] = $appointment
                ? $this->links->issue($message->account, MagicLinkPurpose::Appointment, $appointment, $by)
                : $this->links->issue($message->account, MagicLinkPurpose::Access, null, $by);
        }

        $replacements = collect($params)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all();

        return ['body' => strtr($body, $replacements), 'params' => $params];
    }

    /** Enlace wa.me con el mensaje escrito; queda marcado como abierto por quien lo envía. */
    public function clickToChatUrl(WhatsappMessage $message, User $by): string
    {
        $body = $this->render($message, $by);

        $message->forceFill([
            'status' => MessageStatus::Opened,
            'opened_at' => now(),
            'opened_by_user_id' => $by->getKey(),
        ])->save();

        return 'https://wa.me/'.Phone::forWhatsApp($message->phone).'?text='.rawurlencode($body);
    }

    public function markSent(WhatsappMessage $message, ?User $by = null, ?string $providerId = null): void
    {
        $message->forceFill([
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
            'sent_by_user_id' => $by?->getKey(),
            'provider_message_id' => $providerId,
        ])->save();
    }

    public function channel(): MessageChannel
    {
        return config('consultorio.whatsapp.driver') === 'cloud_api'
            ? MessageChannel::CloudApi
            : MessageChannel::ClickToChat;
    }

    /** @return array<string, string> */
    private function appointmentParams(Appointment $appointment): array
    {
        return [
            'nombre' => $appointment->patient->first_name,
            'paciente' => $appointment->patient->full_name,
            'fecha' => $appointment->starts_at->translatedFormat('l j \d\e F'),
            'hora' => $appointment->starts_at->format('g:i a'),
            'doctor' => $appointment->doctor->name,
            'tratamiento' => mb_strtolower($appointment->treatment->name),
            'consultorio' => (string) Setting::get('clinic.name'),
            'direccion' => (string) Setting::get('clinic.address'),
        ];
    }
}
