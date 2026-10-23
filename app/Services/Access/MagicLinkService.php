<?php

namespace App\Services\Access;

use App\Enums\MagicLinkPurpose;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\MagicLink;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Enlaces personales que viajan en los mensajes de WhatsApp.
 * Tocar el enlace verifica el celular y recuerda el dispositivo (sin códigos OTP).
 */
class MagicLinkService
{
    /** Devuelve la URL completa; el token en claro no se guarda en ningún lado. */
    public function issue(Account $account, MagicLinkPurpose $purpose, ?Appointment $appointment = null, ?User $by = null): string
    {
        $token = Str::random(32);

        $expiresAt = match ($purpose) {
            MagicLinkPurpose::Appointment => $appointment->starts_at->copy()
                ->addDays((int) Setting::get('links.appointment_link_days_after', 2)),
            default => now()->addHours((int) Setting::get('links.access_link_hours', 72)),
        };

        $account->magicLinks()->create([
            'appointment_id' => $appointment?->getKey(),
            'purpose' => $purpose,
            'token_hash' => self::hash($token),
            'expires_at' => $expiresAt,
            'created_by_user_id' => $by?->getKey(),
        ]);

        return route('patient.link', ['token' => $token]);
    }

    /** Busca un enlace vigente sin marcarlo como usado (abrirlo no confirma nada). */
    public function find(string $token): ?MagicLink
    {
        $link = MagicLink::query()->where('token_hash', self::hash($token))->first();

        return $link?->isUsable() ? $link : null;
    }

    /**
     * El paciente actuó desde el enlace (tocó un botón): el celular queda verificado.
     * Se llama recién en la acción para que la vista previa de WhatsApp no cuente como uso.
     */
    public function consume(MagicLink $link): void
    {
        $link->forceFill([
            'first_used_at' => $link->first_used_at ?? now(),
            'last_used_at' => now(),
            'use_count' => $link->use_count + 1,
        ])->save();

        $account = $link->account;

        if (! $account->isVerified()) {
            $account->forceFill(['phone_verified_at' => now()])->save();
        }
    }

    public function revokeForAppointment(Appointment $appointment): void
    {
        MagicLink::query()
            ->where('appointment_id', $appointment->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
