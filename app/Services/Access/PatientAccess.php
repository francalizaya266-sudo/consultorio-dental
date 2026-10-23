<?php

namespace App\Services\Access;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\MagicLink;
use App\Models\RememberedDevice;
use App\Models\WhatsappMessage;
use App\Services\StaffAlerts;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Phone;
use Illuminate\Http\Request;

/**
 * Quién puede ver o cambiar una cita desde la web: quien tiene el enlace de esa cita
 * o el dispositivo recordado del celular del paciente. Sin contraseñas ni códigos.
 */
class PatientAccess
{
    public function __construct(
        private readonly MagicLinkService $links,
        private readonly DeviceService $devices,
        private readonly WhatsAppOutbox $outbox,
        private readonly StaffAlerts $alerts,
    ) {}

    public function device(Request $request): ?RememberedDevice
    {
        return $this->devices->current($request);
    }

    /** ¿Esta petición puede actuar sobre la cita? Con su enlace vigente o desde el celular recordado. */
    public function canAccess(Request $request, Appointment $appointment, ?string $token = null): bool
    {
        if ($token !== null) {
            return $this->links->find($token)?->appointment_id === $appointment->getKey();
        }

        $appointment->loadMissing('patient');

        return $this->device($request)?->account_id === $appointment->patient->account_id;
    }

    /**
     * El paciente tocó un botón del enlace: el celular queda verificado y este
     * dispositivo, recordado (con la verificación reforzada por unos minutos).
     */
    public function actWithLink(Request $request, MagicLink $link): void
    {
        $this->links->consume($link);

        if ($this->device($request)?->account_id !== $link->account_id) {
            $this->devices->remember($link->account, $request);
        }

        $this->devices->elevate();
    }

    /**
     * "Pedir acceso": deja listo en la bandeja el enlace de Mi cuenta para que recepción
     * lo envíe. No dice si el número existe, y no repite el aviso si ya hay uno pendiente.
     */
    public function requestAccess(string $phone): void
    {
        $account = Phone::isValid($phone) ? Account::findByPhone($phone) : null;

        if ($account === null || $account->isBlocked()) {
            return;
        }

        $pending = WhatsappMessage::query()
            ->whereBelongsTo($account)
            ->where('template_key', MessageTemplateKey::AccessLink)
            ->where('status', MessageStatus::Pending)
            ->exists();

        if ($pending) {
            return;
        }

        $this->outbox->queueForAccount($account, MessageTemplateKey::AccessLink, patientId: $account->holder?->getKey());

        $name = $account->holder?->full_name ?? Phone::display($account->phone);
        $this->alerts->toReception('Pidió su enlace de Mi cuenta', "{$name} quiere entrar a Mi cuenta. El enlace está listo en Avisos por enviar.");
    }
}
