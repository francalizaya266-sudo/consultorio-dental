<?php

namespace App\Services\Reports;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentPlanStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanInstallment;
use App\Services\Billing\BillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * El dinero que está esperando: presupuestos presentados que nadie respondió, saldos y cuotas atrasadas, cuántos
 * presupuestos se aceptan y lo que se perdió con cada falta. Lo que espera es de hoy; la aceptación y las faltas,
 * del período. Con un doctor, solo lo suyo (los saldos son del paciente, no de un doctor: van sin filtro).
 */
final class MoneyWaiting
{
    public function __construct(
        public readonly ReportPeriod $period,
        public readonly ?int $doctorId = null,
    ) {}

    /**
     * Presupuestos presentados que siguen sin respuesta, el de más plata primero.
     *
     * @return Collection<int, array{plan: TreatmentPlan, total: float, waiting_days: int, expired: bool}>
     */
    public function unansweredPlans(): Collection
    {
        return TreatmentPlan::query()
            ->where('status', TreatmentPlanStatus::Proposed)
            ->whereNull('included_in_plan_id')
            ->whereHas('patient')
            ->when($this->doctorId, fn (Builder $plans, int $id) => $plans->where('doctor_id', $id))
            ->with(['patient', 'doctor', 'items'])
            ->get()
            ->map(fn (TreatmentPlan $plan) => [
                'plan' => $plan,
                'total' => $plan->total(),
                'waiting_days' => (int) ($plan->proposed_at ?? $plan->created_at)->copy()->startOfDay()->diffInDays(today()),
                'expired' => (bool) $plan->validUntil()?->isPast(),
            ])
            ->filter(fn (array $row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Quién debe y cuánto, el que más debe primero.
     *
     * @return array{count: int, amount: float, patients: Collection<int, Patient>}
     */
    public function balances(int $limit = 5): array
    {
        $owing = fn () => Patient::query()->owing();
        $amounts = $owing()->withBalance()->pluck('balance_due');

        return [
            'count' => $amounts->count(),
            'amount' => round((float) $amounts->sum(), 2),
            'patients' => $owing()->withBalance()->orderByDesc('balance_due')->limit($limit)->get(),
        ];
    }

    /**
     * Cuotas vencidas sin pagar, la más atrasada primero.
     *
     * @return array{count: int, amount: float, installments: Collection<int, array{installment: TreatmentPlanInstallment, due: float}>}
     */
    public function lateInstallments(int $limit = 5): array
    {
        $due = app(BillingService::class)->installmentsIn(['late']);
        $installments = TreatmentPlanInstallment::query()
            ->whereKey(array_keys($due))
            ->when($this->doctorId, fn (Builder $installments, int $id) => $installments->whereHas('plan', fn (Builder $plans) => $plans->where('doctor_id', $id)))
            ->with('plan.patient', 'plan.installments')
            ->orderBy('due_on')
            ->get();

        return [
            'count' => $installments->count(),
            'amount' => round($installments->sum(fn (TreatmentPlanInstallment $installment) => $due[$installment->getKey()]), 2),
            'installments' => $installments->take($limit)->map(fn (TreatmentPlanInstallment $installment) => ['installment' => $installment, 'due' => $due[$installment->getKey()]])->values(),
        ];
    }

    /**
     * De los presupuestos presentados en el período, cuántos se aceptaron (hasta hoy) y por cuánto. Uno que se
     * firmó en el momento, sin pasar por "presentado", cuenta como presentado y aceptado ese día.
     *
     * @return array{presented: int, accepted: int, rate: float|null, presented_amount: float, accepted_amount: float}
     */
    public function acceptance(): array
    {
        $plans = TreatmentPlan::query()
            ->whereNull('included_in_plan_id')
            ->whereNot('status', TreatmentPlanStatus::Draft)
            ->where(fn (Builder $plans) => $plans
                ->whereBetween('proposed_at', [$this->period->from, $this->period->to])
                ->orWhere(fn (Builder $signed) => $signed->whereNull('proposed_at')->whereBetween('accepted_at', [$this->period->from, $this->period->to])))
            ->when($this->doctorId, fn (Builder $plans, int $id) => $plans->where('doctor_id', $id))
            ->with('items')
            ->get();
        $accepted = $plans->filter(fn (TreatmentPlan $plan) => $plan->accepted_at !== null);

        return [
            'presented' => $plans->count(),
            'accepted' => $accepted->count(),
            'rate' => $plans->isNotEmpty() ? round($accepted->count() / $plans->count() * 100, 1) : null,
            'presented_amount' => round($plans->sum(fn (TreatmentPlan $plan) => $plan->total()), 2),
            'accepted_amount' => round($accepted->sum(fn (TreatmentPlan $plan) => $plan->total()), 2),
        ];
    }

    /**
     * Las faltas del período con nombre: lo que valía esa cita (la sesión de su plan o el precio del tratamiento)
     * y si después volvió a agendar.
     *
     * @return Collection<int, array{appointment: Appointment, lost: float|null, rebooked: bool}>
     */
    public function noShows(): Collection
    {
        $noShows = Appointment::query()
            ->where('status', AppointmentStatus::NoShow)
            ->whereBetween('starts_at', [$this->period->from, $this->period->to])
            ->when($this->doctorId, fn (Builder $appointments, int $id) => $appointments->where('doctor_id', $id))
            ->whereHas('patient')
            ->with(['patient', 'doctor', 'treatment', 'planItem'])
            ->latest('starts_at')
            ->get();

        // Volvió a agendar: tiene otra cita (atendida o por venir) después de la que faltó.
        $later = Appointment::query()
            ->whereIn('patient_id', $noShows->pluck('patient_id')->unique())
            ->whereIn('status', AppointmentStatus::occupying())
            ->get(['patient_id', 'starts_at'])
            ->groupBy('patient_id');

        return $noShows->map(fn (Appointment $appointment) => [
            'appointment' => $appointment,
            'lost' => self::valueOf($appointment),
            'rebooked' => ($later[$appointment->patient_id] ?? collect())->contains(fn (Appointment $other) => $other->starts_at->gt($appointment->starts_at)),
        ]);
    }

    /** Lo que valía la cita: la sesión del plan (el procedimiento entre sus sesiones) o el precio del tratamiento. */
    private static function valueOf(Appointment $appointment): ?float
    {
        if ($item = $appointment->planItem) {
            return round($item->subtotal() / max(1, (int) $item->sessions_planned), 2);
        }

        $price = $appointment->treatment?->price_from;

        return $price !== null && (float) $price > 0 ? (float) $price : null;
    }
}
