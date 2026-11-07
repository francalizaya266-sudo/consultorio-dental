<?php

namespace App\Filament\Support;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Patients\PatientInsights;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que sigue al terminar la atención, en una tarjeta arriba de la atención terminada: cobrar (o avisar a
 * recepción, si quien atendió no maneja la caja) y la próxima sesión con su fecha sugerida.
 */
final class VisitCloseOut
{
    /**
     * @return array{payment: array{label: string, detail: string, button: string|null, action: string|null, done: bool}, next: array{label: string, detail: string, url: string|null, done: bool}}|null
     */
    public static function viewData(Consultation $consultation, User $user): ?array
    {
        if ($consultation->closed_at === null || ! $consultation->closed_at->isToday()) {
            return null;
        }

        return [
            'payment' => self::payment($consultation, $user),
            'next' => self::next($consultation, $user),
        ];
    }

    /** Recepción ya sabe que tiene que cobrarle (se avisa una vez por atención). */
    public static function receptionNotified(Consultation $consultation): bool
    {
        return Cache::has(self::notifiedKey($consultation));
    }

    public static function markReceptionNotified(Consultation $consultation): void
    {
        Cache::put(self::notifiedKey($consultation), true, now()->endOfDay());
    }

    /** @return array{label: string, detail: string, button: string|null, action: string|null, done: bool} */
    private static function payment(Consultation $consultation, User $user): array
    {
        $charged = Charge::query()->valid()->where('consultation_id', $consultation->getKey())->get();
        $balance = $consultation->patient->balance();
        $detail = $charged->isEmpty()
            ? 'Hoy no se cargó nada'
            : 'Se cargó '.Money::format($charged->sum(fn (Charge $charge) => $charge->net())).': '.$charged->pluck('description')->implode(' · ');

        if ($balance <= 0) {
            return ['label' => 'No tiene nada por pagar', 'detail' => $detail, 'button' => null, 'action' => null, 'done' => true];
        }

        $label = 'Debe '.Money::format($balance);

        if ($user->can('create', Payment::class)) {
            return ['label' => $label, 'detail' => $detail, 'button' => 'Cobrar ahora', 'action' => 'collect', 'done' => false];
        }

        return self::receptionNotified($consultation)
            ? ['label' => $label, 'detail' => 'Recepción ya sabe que tiene que cobrarle.', 'button' => null, 'action' => null, 'done' => true]
            : ['label' => $label, 'detail' => $detail, 'button' => 'Avisar a recepción', 'action' => 'notifyReception', 'done' => false];
    }

    /** @return array{label: string, detail: string, url: string|null, done: bool} */
    private static function next(Consultation $consultation, User $user): array
    {
        $insights = app(PatientInsights::class);
        $patient = $consultation->patient;

        if ($booked = $insights->upcomingVisit($patient)) {
            return [
                'label' => 'Ya tiene su próxima cita',
                'detail' => ucfirst($booked->starts_at->translatedFormat('l j \d\e F, g:i a')).' · '.$booked->treatment->name,
                'url' => null,
                'done' => true,
            ];
        }

        $canBook = $user->can('create', Appointment::class);
        $suggested = $insights->suggestedNextVisit($patient);

        if (! $suggested) {
            return [
                'label' => 'Su plan no tiene otra sesión pendiente',
                'detail' => 'Si necesita volver, prográmale una cita.',
                'url' => $canBook ? AppointmentResource::nextVisitUrl($patient, $consultation->doctor_id) : null,
                'done' => false,
            ];
        }

        return [
            'label' => 'Próxima: '.$suggested['label'],
            'detail' => 'Sugerida el '.$suggested['on']->translatedFormat('l j \d\e F').' · '.$suggested['why'],
            'url' => $canBook ? AppointmentResource::nextVisitUrl($patient, $consultation->doctor_id, $suggested['on']) : null,
            'done' => false,
        ];
    }

    private static function notifiedKey(Consultation $consultation): string
    {
        return 'close-out:reception-notified:'.$consultation->getKey();
    }
}
