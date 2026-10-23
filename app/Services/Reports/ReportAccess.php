<?php

namespace App\Services\Reports;

use App\Models\Setting;
use App\Models\User;

/**
 * Quién ve ganancias y gráficos: la administración, todo; un doctor, solo lo suyo y
 * solo si el consultorio lo activó en Ajustes. Recepción, nada.
 */
final class ReportAccess
{
    public static function canView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin()
            || ($user->isDoctor() && $user->doctorId() !== null && (bool) Setting::get('reports.doctors_see_own'));
    }

    /** El doctor cuyos números se muestran: el elegido en el filtro (administración) o el propio (doctor). Null es todos. */
    public static function doctorFor(User $user, mixed $requested): ?int
    {
        if ($user->isAdmin()) {
            return filled($requested) ? (int) $requested : null;
        }

        return $user->doctorId();
    }

    /** La administración puede elegir el doctor; el doctor solo ve lo suyo. */
    public static function choosesDoctor(?User $user): bool
    {
        return (bool) $user?->isAdmin();
    }
}
