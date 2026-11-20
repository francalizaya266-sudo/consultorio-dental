<?php

namespace App\Enums;

use App\Models\User;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Cookie;

/**
 * Cómo se mira "Hoy". "Mi consulta" es la del que atiende: su próximo paciente y sus citas. "Toda la clínica" es la
 * de recepción y administración: la sala, lo que hay que hacer, los huecos, la caja y el mes.
 *
 * Solo elige el administrador que también atiende (canChoose): los demás tienen una sola forma de ver el día.
 * La elección se recuerda en esa computadora (la del consultorio puede quedar en "Mi consulta" y la del mostrador
 * en "Toda la clínica") y nunca cambia sola. La primera vez abre en "Mi consulta". No cambia permisos: cada
 * tarjeta sigue pidiendo el suyo.
 */
enum TodayView: string implements HasLabel
{
    case Mine = 'mi-consulta';
    case Clinic = 'clinica';

    /** El parámetro del enlace del selector: /panel?vista=clinica. */
    public const QUERY = 'vista';

    private const SESSION_KEY = 'today.view';

    private const COOKIE = 'hoy_vista';

    /** Un año: la elección sobrevive al cerrar sesión. */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function getLabel(): string
    {
        return match ($this) {
            self::Mine => 'Mi consulta',
            self::Clinic => 'Toda la clínica',
        };
    }

    /** Administra y además atiende con agenda propia: le sirven las dos vistas. */
    public static function canChoose(?User $user): bool
    {
        return $user !== null && $user->isAdmin() && $user->canSeeClinical() && $user->doctorId() !== null;
    }

    /** La vista con que esa persona ve "Hoy" ahora: la que eligió o la única que le toca. */
    public static function current(?User $user = null): self
    {
        /** @var User|null $user */
        $user ??= auth()->user();

        if (! self::canChoose($user)) {
            return $user?->managesAllAgendas() ? self::Clinic : self::Mine;
        }

        return self::tryFrom((string) session(self::SESSION_KEY, request()->cookie(self::COOKIE))) ?? self::Mine;
    }

    /** Desde aquí "Hoy" abre así en esta computadora, hasta que se elija la otra. */
    public function remember(): void
    {
        session()->put(self::SESSION_KEY, $this->value);
        Cookie::queue(self::COOKIE, $this->value, self::COOKIE_MINUTES);
    }
}
