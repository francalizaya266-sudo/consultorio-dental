<?php

namespace App\Filament\Support;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Patients\PatientInsights;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que sigue al terminar la atención, en una tarjeta arriba de la atención terminada: el presupuesto que
 * se armó (para que lo acepte y firme, o se lo lleve impreso o por WhatsApp), cobrar (o avisar a recepción,
 * si quien atendió no maneja la caja) y la próxima sesión con su fecha sugerida.
 */
final class VisitCloseOut
{
    /**
     * @return array{plan: array{label: string, detail: string, button: string|null, action: string|null, done: bool, links: list<array{label: string, url?: string, action?: string}>, options: list<string>}|null, payment: array{label: string, detail: string, button: string|null, action: string|null, done: bool}, next: array{label: string, detail: string, url: string|null, done: bool}}|null
     */
    public static function viewData(Consultation $consultation, User $user): ?array
    {
        if ($consultation->closed_at === null || ! $consultation->closed_at->isToday()) {
            return null;
        }

        return [
            'plan' => self::plan($consultation, $user),
            'payment' => self::payment($consultation, $user),
            'next' => self::next($consultation, $user),
        ];
    }

    /**
     * El presupuesto que el paciente tiene por aceptar: el que se armó en esta atención o, si no, el último con
     * algo sin aceptar (también lo que se agregó a un plan que ya aceptó). Null si no tiene nada por aceptar.
     */
    public static function planToAccept(Consultation $consultation): ?TreatmentPlan
    {
        return TreatmentPlan::query()
            ->open()
            ->where('patient_id', $consultation->patient_id)
            ->whereNull('included_in_plan_id')
            ->with(['items', 'patient.account'])
            ->get()
            ->filter(fn (TreatmentPlan $plan) => $plan->itemsToAccept()->isNotEmpty())
            ->sortByDesc(fn (TreatmentPlan $plan) => [(int) $plan->consultation_id === (int) $consultation->getKey(), $plan->getKey()])
            ->first();
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

    /** @return array{label: string, detail: string, button: string|null, action: string|null, done: bool, links: list<array{label: string, url?: string, action?: string}>, options: list<string>}|null */
    private static function plan(Consultation $consultation, User $user): ?array
    {
        $plan = self::planToAccept($consultation);

        if (! $plan) {
            $signed = self::signedToday($consultation);

            return $signed ? [
                'label' => 'Aceptó su plan: '.Money::format($signed->total()),
                'detail' => app(TreatmentPlanService::class)->paymentText($signed),
                'button' => null,
                'action' => null,
                'done' => true,
                'links' => [],
                'options' => [],
            ] : null;
        }

        $first = $plan->accepted_at === null;
        $items = $plan->itemsToAccept();
        $canSign = $user->can('update', $plan);
        $names = $items->take(3)->map(fn (TreatmentPlanItem $item) => $item->label())->implode(' · ')
            .($items->count() > 3 ? ' y '.($items->count() - 3).' más' : '');
        $until = $plan->validUntil();

        return [
            'label' => ($first ? 'Presupuesto por aceptar: ' : 'Lo agregado a su plan, por aceptar: ').Money::format($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal())),
            'detail' => $names.($until ? ' · vale hasta el '.$until->format('d/m/Y') : ''),
            'options' => $first ? app(TreatmentPlanService::class)->paymentOptions($plan) : [],
            'button' => $canSign ? ($first ? 'Acepta y firma' : 'Firma lo agregado') : null,
            'action' => $canSign ? 'acceptPlan' : null,
            'done' => false,
            'links' => array_values(array_filter([
                ['label' => 'Imprimir', 'url' => TreatmentPlansRelationManager::printUrl($plan)],
                $canSign && $plan->patient->account !== null ? ['label' => 'WhatsApp', 'action' => 'sendPlan'] : null,
            ])),
        ];
    }

    /** El plan que aceptó o firmó hoy, para dejarlo a la vista ya resuelto. */
    private static function signedToday(Consultation $consultation): ?TreatmentPlan
    {
        return TreatmentPlan::query()
            ->open()
            ->where('patient_id', $consultation->patient_id)
            ->whereNull('included_in_plan_id')
            ->where(fn ($query) => $query
                ->where('accepted_at', '>=', today())
                ->orWhereHas('signatures', fn ($signatures) => $signatures->where('signed_at', '>=', today())))
            ->with(['items', 'installments'])
            ->latest('id')
            ->first();
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

    /**
     * La próxima sesión de su plan. Si todavía no acepta el presupuesto, se ofrece su primera sesión: al
     * agendarla se pregunta si ya lo aceptó.
     *
     * @return array{label: string, detail: string, url: string|null, done: bool}
     */
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
        $suggested = $insights->suggestedNextVisit($patient, evenIfNotAccepted: true);

        if (! $suggested) {
            return [
                'label' => 'No tiene otra sesión pendiente',
                'detail' => 'Si necesita volver, prográmale una cita.',
                'url' => $canBook ? AppointmentResource::nextVisitUrl($patient, $consultation->doctor_id) : null,
                'done' => false,
            ];
        }

        $waitsForAcceptance = $suggested['item']->plan->accepted_at === null;

        return [
            'label' => ($waitsForAcceptance ? 'Primera sesión del presupuesto: ' : 'Próxima: ').$suggested['label'],
            'detail' => 'Sugerida el '.$suggested['on']->translatedFormat('l j \d\e F').' · '.$suggested['why'],
            'url' => $canBook ? AppointmentResource::nextVisitUrl($patient, $consultation->doctor_id, $suggested['on'], $suggested['item']) : null,
            'done' => false,
        ];
    }

    private static function notifiedKey(Consultation $consultation): string
    {
        return 'close-out:reception-notified:'.$consultation->getKey();
    }
}
