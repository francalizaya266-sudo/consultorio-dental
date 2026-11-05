<?php

namespace App\Filament\Support;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\User;
use App\Support\Money;
use App\Support\Phone;

/**
 * Lo que hay que saber de un paciente de un vistazo, en etiquetas bajo su nombre: cuánto debe,
 * cuándo vuelve, si le toca control o dejó un plan a medias, si cumple años y cómo nos encontró.
 * Cada etiqueta lleva a donde se resuelve. El color dice si hay algo que hacer.
 */
final class PatientSummary
{
    /** El encabezado: "38 años · DNI 60075025 · San Isidro · paciente desde oct. 2026". */
    public static function subheading(Patient $patient): ?string
    {
        $since = $patient->first_visit_at;

        return collect([
            $patient->age !== null ? $patient->age.($patient->age === 1 ? ' año' : ' años') : null,
            $patient->document_number ? $patient->document_type?->getLabel().' '.$patient->document_number : null,
            $patient->district,
            $since ? 'paciente desde '.$since->translatedFormat('M Y') : null,
        ])->filter()->implode(' · ') ?: null;
    }

    /** @return list<array{label: string, color: string, icon: string, url: string|null}> */
    public static function chips(Patient $patient, User $user): array
    {
        $next = AppointmentResource::getEloquentQuery()
            ->whereBelongsTo($patient)
            ->where('starts_at', '>=', today())
            ->whereIn('status', AppointmentStatus::changeable())
            ->orderBy('starts_at')
            ->first();

        return array_values(array_filter([
            $user->can('viewAny', Charge::class) ? self::balance($patient) : null,
            self::nextVisit($patient, $next),
            $next === null ? self::lastVisit($patient) : null,
            self::unfinishedPlan($patient),
            self::birthday($patient),
            self::origin($patient, $user),
        ]));
    }

    /**
     * Para escribirle o llamarlo desde la ficha.
     *
     * @return array{whatsapp: string, call: string}|null
     */
    public static function contact(Patient $patient): ?array
    {
        $phone = $patient->account?->phone;

        return $phone ? ['whatsapp' => 'https://wa.me/'.Phone::forWhatsApp($phone), 'call' => 'tel:'.Phone::normalize($phone)] : null;
    }

    /** @return array{label: string, color: string, icon: string, url: string|null} */
    private static function balance(Patient $patient): array
    {
        $balance = $patient->balance();

        return [
            'label' => Money::balance($balance),
            'color' => match (true) {
                $balance > 0 => 'danger',
                $balance < 0 => 'info',
                default => 'success',
            },
            'icon' => 'heroicon-m-banknotes',
            'url' => null,
        ];
    }

    /** @return array{label: string, color: string, icon: string, url: string|null} */
    private static function nextVisit(Patient $patient, ?Appointment $next): array
    {
        if ($next === null) {
            return [
                'label' => 'Sin cita agendada',
                'color' => 'gray',
                'icon' => 'heroicon-m-calendar',
                'url' => AppointmentResource::getUrl('create', ['patient' => $patient->getKey()]),
            ];
        }

        $when = match (true) {
            $next->starts_at->isToday() => 'hoy',
            $next->starts_at->isTomorrow() => 'mañana',
            default => $next->starts_at->translatedFormat('D j M'),
        };

        return [
            'label' => 'Próxima cita: '.$when.' · '.$next->starts_at->format('H:i'),
            'color' => 'info',
            'icon' => 'heroicon-m-calendar-days',
            'url' => AppointmentResource::getUrl('view', ['record' => $next]),
        ];
    }

    /**
     * Sin cita por delante: cuándo vino por última vez. Pasados 6 meses, le toca control.
     *
     * @return array{label: string, color: string, icon: string, url: string|null}|null
     */
    private static function lastVisit(Patient $patient): ?array
    {
        $last = $patient->last_visit_at;

        if ($last === null) {
            return ['label' => 'Aún no viene', 'color' => 'gray', 'icon' => 'heroicon-m-sparkles', 'url' => null];
        }

        $due = $last->lte(now()->subMonths(6));

        return [
            'label' => ($due ? 'Le toca control · ' : '').'Última visita '.$last->diffForHumans(),
            'color' => $due ? 'warning' : 'gray',
            'icon' => 'heroicon-m-clock',
            'url' => $due ? AppointmentResource::getUrl('create', ['patient' => $patient->getKey()]) : null,
        ];
    }

    /** @return array{label: string, color: string, icon: string, url: string|null}|null */
    private static function unfinishedPlan(Patient $patient): ?array
    {
        $patients = Patient::query()->whereKey($patient->getKey());

        return match (true) {
            (clone $patients)->withUnscheduledPlan()->exists() => [
                'label' => 'Plan a medias: falta agendar',
                'color' => 'warning',
                'icon' => 'heroicon-m-clipboard-document-list',
                'url' => AppointmentResource::getUrl('create', ['patient' => $patient->getKey()]),
            ],
            (clone $patients)->withPlanAwaitingAnswer()->exists() => [
                'label' => 'Presupuesto sin respuesta',
                'color' => 'warning',
                'icon' => 'heroicon-m-clipboard-document-list',
                'url' => null,
            ],
            default => null,
        };
    }

    /** @return array{label: string, color: string, icon: string, url: string|null}|null */
    private static function birthday(Patient $patient): ?array
    {
        $birth = $patient->birth_date;

        if ($birth === null || ! Patient::query()->whereKey($patient->getKey())->birthdayOn(today())->exists()) {
            return null;
        }

        return ['label' => 'Hoy cumple '.$patient->age.' años', 'color' => 'success', 'icon' => 'heroicon-m-cake', 'url' => null];
    }

    /**
     * Cómo nos encontró. Si falta, se pide: sin ese dato, Reportes no sabe qué publicidad funciona.
     *
     * @return array{label: string, color: string, icon: string, url: string|null}|null
     */
    private static function origin(Patient $patient, User $user): ?array
    {
        if ($patient->origin !== null) {
            return ['label' => 'Origen: '.$patient->origin->getLabel(), 'color' => 'gray', 'icon' => 'heroicon-m-megaphone', 'url' => null];
        }

        return $user->can('update', $patient)
            ? ['label' => 'Falta: ¿cómo nos encontró?', 'color' => 'warning', 'icon' => 'heroicon-m-megaphone', 'url' => PatientResource::getUrl('edit', ['record' => $patient])]
            : null;
    }
}
