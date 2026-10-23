<?php

namespace App\Services\Access;

use App\Models\Account;
use App\Models\RememberedDevice;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Dispositivo recordado: el paciente reserva con un toque y ve sus próximas citas.
 * Para citas pasadas o datos personales se pide una verificación reforzada
 * (un enlace nuevo por WhatsApp) que dura pocos minutos en la sesión.
 */
class DeviceService
{
    public const COOKIE = 'cd_device';

    private const ELEVATED_SESSION_KEY = 'patient.elevated_until';

    public function remember(Account $account, Request $request): RememberedDevice
    {
        $token = Str::random(48);
        $days = (int) Setting::get('devices.remember_days', 120);

        $device = $account->rememberedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $token, $days * 24 * 60, httpOnly: true, sameSite: 'lax'));

        return $device;
    }

    /** Dispositivo recordado de esta petición; cada uso extiende su vigencia. */
    public function current(Request $request): ?RememberedDevice
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $device = RememberedDevice::query()
            ->usable()
            ->where('token_hash', hash('sha256', $token))
            ->with('account')
            ->first();

        if ($device === null || $device->account === null || $device->account->isBlocked()) {
            return null;
        }

        $device->forceFill([
            'last_used_at' => now(),
            'expires_at' => now()->addDays((int) Setting::get('devices.remember_days', 120)),
        ])->save();

        return $device;
    }

    /** "Salir en este celular": deja de estar recordado. */
    public function forget(Request $request): void
    {
        $token = $request->cookie(self::COOKIE);

        if (is_string($token) && $token !== '') {
            RememberedDevice::query()->where('token_hash', hash('sha256', $token))->update(['revoked_at' => now()]);
        }

        session()->forget(self::ELEVATED_SESSION_KEY);
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** "Cerrar sesión en todos mis dispositivos". */
    public function forgetAll(Account $account): void
    {
        $account->rememberedDevices()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    public function elevate(): void
    {
        $minutes = (int) Setting::get('devices.elevated_minutes', 15);
        session()->put(self::ELEVATED_SESSION_KEY, now()->addMinutes($minutes)->getTimestamp());
    }

    public function isElevated(): bool
    {
        return (int) session()->get(self::ELEVATED_SESSION_KEY, 0) > now()->getTimestamp();
    }
}
