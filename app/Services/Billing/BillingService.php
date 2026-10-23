<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Enums\PlanItemStatus;
use App\Enums\TreatmentPlanStatus;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanInstallment;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cargos y pagos. Lo realizado se carga solo al terminar la atención (o al marcar la cita
 * como atendida): la sesión del procedimiento del plan, con el precio acordado, o el
 * tratamiento de la cita con su precio y la promoción. Lo que el paciente aceptó pagar con
 * cronograma (pago único o cuotas) no se carga por sesión: se carga cada pago al vencer.
 * Recepción lo revisa al cobrar. Saldo = cargos − pagos, sin los anulados; los pagos cubren
 * primero los cargos más antiguos. Nada se borra: se anula con motivo.
 */
final class BillingService
{
    /** Motivo de las anulaciones que hace el sistema al corregirse una atención. */
    public const CORRECTED = 'Se corrigió la atención';

    private const CHECKOUT_DISCOUNT = 'Descuento al cobrar';

    /** Carga lo trabajado en la atención. Si se reabre y se vuelve a cerrar, no se carga dos veces. */
    public function chargeConsultation(Consultation $consultation, User $by): void
    {
        $consultation->loadMissing(['appointment.treatment', 'appointment.campaign', 'planItems.plan']);

        DB::transaction(function () use ($consultation, $by) {
            $charges = Charge::query()->where('consultation_id', $consultation->getKey())->get();
            $expected = [];

            $add = function (string $key, callable $create) use ($charges, &$expected) {
                $expected[] = $key;

                // Un cargo que recepción quitó a mano no vuelve; uno que quitó el sistema, sí.
                $handled = $charges->contains(fn (Charge $c) => self::key($c) === $key && $c->void_reason !== self::CORRECTED);

                if (! $handled) {
                    $create();
                }
            };

            foreach ($consultation->planItems as $item) {
                $add('item-'.$item->getKey(), fn () => $this->chargePlanSession($item, $by, $consultation->appointment, $consultation));
            }

            $appointment = $consultation->appointment;
            $workedTreatments = $consultation->planItems->pluck('treatment_id')->filter()->all();

            // Si lo trabajado del plan es el mismo tratamiento de la cita, la cita ya está en el plan.
            if ($appointment && $appointment->treatment_plan_item_id === null && ! in_array($appointment->treatment_id, $workedTreatments, true)) {
                $add('treatment-'.$appointment->treatment_id, fn () => $this->chargeTreatment($appointment, $by, $consultation));
            }

            $charges
                ->filter(fn (Charge $c) => $c->voided_at === null && ! in_array(self::key($c), $expected, true))
                ->each(fn (Charge $c) => $this->void($c, $by, self::CORRECTED));
        });
    }

    /** Cita marcada como atendida sin atención clínica escrita: se carga su tratamiento o la sesión del plan. */
    public function chargeAppointment(Appointment $appointment, User $by): void
    {
        if ($appointment->consultation()->exists() || Charge::query()->whereBelongsTo($appointment)->exists()) {
            return;
        }

        $appointment->loadMissing(['planItem.plan', 'treatment', 'campaign']);

        if ($appointment->planItem) {
            $this->chargePlanSession($appointment->planItem, $by, $appointment);
        } else {
            $this->chargeTreatment($appointment, $by);
        }
    }

    /**
     * Una sesión del procedimiento: su parte del precio acordado en el plan. La última sesión
     * carga lo que falta, así el total cargado es exactamente el del presupuesto.
     */
    public function chargePlanSession(TreatmentPlanItem $item, User $by, ?Appointment $appointment = null, ?Consultation $consultation = null): ?Charge
    {
        // Ya se paga con el cronograma del plan.
        if ($item->in_payment_schedule) {
            return null;
        }

        [$amount, $session] = $this->nextSession($item);

        if ($amount <= 0) {
            return null;
        }

        $sessions = max(1, (int) $item->sessions_planned);

        return Charge::create([
            'patient_id' => $item->plan->patient_id,
            'appointment_id' => $appointment?->getKey(),
            'consultation_id' => $consultation?->getKey(),
            'treatment_plan_item_id' => $item->getKey(),
            'treatment_id' => $item->treatment_id,
            'doctor_id' => $consultation?->doctor_id ?? $appointment?->doctor_id,
            'description' => $item->label().($sessions > 1 ? " · sesión {$session} de {$sessions}" : ''),
            'amount' => $amount,
            'charged_at' => now(),
            'created_by_user_id' => $by->getKey(),
        ]);
    }

    /** El tratamiento de la cita a su precio de lista, con el descuento de la promoción con que reservó. */
    public function chargeTreatment(Appointment $appointment, User $by, ?Consultation $consultation = null): ?Charge
    {
        $treatment = $appointment->treatment;
        $price = (float) $treatment?->price_from;

        if ($price <= 0) {
            return null;
        }

        $discount = $appointment->campaign?->discountOn($price, $treatment->getKey()) ?? 0.0;

        return Charge::create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->getKey(),
            'consultation_id' => $consultation?->getKey(),
            'treatment_id' => $treatment->getKey(),
            'doctor_id' => $appointment->doctor_id,
            'description' => $treatment->name,
            'amount' => $price,
            'discount' => $discount,
            'discount_reason' => $discount > 0 ? 'Promoción: '.$appointment->campaign->name : null,
            'campaign_id' => $discount > 0 ? $appointment->campaign_id : null,
            'charged_at' => now(),
            'created_by_user_id' => $by->getKey(),
        ]);
    }

    /**
     * Cargo a mano: un material, una radiografía, un ajuste de precio.
     *
     * @param  array{description: string, amount: float|string, discount?: float|string|null, discount_reason?: ?string, treatment_id?: ?int, doctor_id?: ?int}  $data
     */
    public function addCharge(Patient $patient, array $data, User $by, ?Appointment $appointment = null): Charge
    {
        return Charge::create([
            'patient_id' => $patient->getKey(),
            'appointment_id' => $appointment?->getKey(),
            'treatment_id' => $data['treatment_id'] ?? $appointment?->treatment_id,
            'doctor_id' => $data['doctor_id'] ?? $appointment?->doctor_id,
            'description' => $data['description'],
            'amount' => $data['amount'],
            'discount' => (float) ($data['discount'] ?? 0),
            'discount_reason' => filled($data['discount'] ?? null) && (float) $data['discount'] > 0 ? ($data['discount_reason'] ?? null) : null,
            'charged_at' => now(),
            'created_by_user_id' => $by->getKey(),
        ]);
    }

    /**
     * Carga los pagos del cronograma que ya vencieron (la inicial, el pago único o la cuota del mes),
     * con la fecha en que vencían. Los de un plan cancelado ya no se cargan.
     *
     * @return int Cuántos se cargaron.
     */
    public function chargeDueInstallments(?Patient $patient = null, ?User $by = null): int
    {
        $due = TreatmentPlanInstallment::query()
            ->whereNull('charge_id')
            ->whereDate('due_on', '<=', today())
            ->whereHas('plan', fn ($query) => $query
                ->whereNot('status', TreatmentPlanStatus::Cancelled)
                ->when($patient, fn ($q) => $q->where('patient_id', $patient->getKey())))
            ->with('plan.installments')
            ->orderBy('due_on')
            ->orderBy('number')
            ->get();

        foreach ($due as $installment) {
            DB::transaction(function () use ($installment, $by) {
                $plan = $installment->plan;
                $charge = Charge::create([
                    'patient_id' => $plan->patient_id,
                    'doctor_id' => $plan->doctor_id,
                    'description' => ($plan->title ?: 'Plan de tratamiento').' · '.mb_strtolower($installment->label()),
                    'amount' => $installment->amount,
                    'discount' => $installment->discount,
                    'discount_reason' => (float) $installment->discount > 0 ? 'Por pagar todo junto' : null,
                    'charged_at' => $installment->due_on->copy()->setTimeFrom(now())->min(now()),
                    'created_by_user_id' => $by?->getKey(),
                ]);

                $installment->update(['charge_id' => $charge->getKey()]);
            });
        }

        return $due->count();
    }

    /**
     * Lo que el paciente todavía debe de cada cargo: los pagos cubren primero los más antiguos.
     *
     * @return Collection<int, array{charge: Charge, due: float}>
     */
    public function unpaidCharges(Patient $patient): Collection
    {
        $paid = (float) $patient->payments()->whereNull('voided_at')->sum('amount');
        $unpaid = collect();

        $charges = $patient->charges()->valid()->orderBy('charged_at')->orderBy('id')->get();

        foreach ($charges as $charge) {
            $covered = min($paid, max(0, $charge->net()));
            $paid = round($paid - $covered, 2);
            $due = round($charge->net() - $covered, 2);

            if ($due > 0) {
                $unpaid->push(['charge' => $charge, 'due' => $due]);
            }
        }

        return $unpaid;
    }

    /**
     * Estado de cada pago del cronograma: pagado, por pagar (ya venció), atrasado (venció antes de hoy) o por vencer.
     *
     * @return list<array{installment: TreatmentPlanInstallment, state: 'paid'|'due'|'late'|'upcoming', due: float}>
     */
    public function installmentStates(TreatmentPlan $plan): array
    {
        $plan->loadMissing('installments.plan.installments');
        $unpaid = $this->unpaidCharges($plan->patient)->keyBy(fn (array $row) => $row['charge']->getKey());
        $today = today();

        return $plan->installments->map(function (TreatmentPlanInstallment $installment) use ($unpaid, $today) {
            $isDue = $installment->due_on->lte($today);
            $due = match (true) {
                $installment->charge_id !== null => (float) ($unpaid->get($installment->charge_id)['due'] ?? 0),
                default => $installment->net(),
            };

            $state = match (true) {
                $installment->charge_id !== null && $due <= 0 => 'paid',
                ! $isDue => 'upcoming',
                $installment->due_on->lt($today) => 'late',
                default => 'due',
            };

            return ['installment' => $installment, 'state' => $state, 'due' => $due];
        })->all();
    }

    /**
     * Cómo va el pago de lo que el paciente aceptó del plan: lo pagado, lo que ya venció y se debe, lo que
     * falta pagar y la próxima cuota. Lo del cronograma, según sus pagos; lo que va por sesión (todo el plan,
     * o lo que se agregó después a un plan en cuotas), según lo cargado de sus procedimientos.
     *
     * @return array{total: float, paid: float, owed: float, remaining: float, late: bool, next: array{installment: TreatmentPlanInstallment, due: float}|null}
     */
    public function planProgress(TreatmentPlan $plan): array
    {
        $plan->loadMissing('items');
        $unpaid = $this->unpaidCharges($plan->patient)->keyBy(fn (array $row) => $row['charge']->getKey());

        $perSession = $plan->items->filter(fn (TreatmentPlanItem $item) => $item->accepted_at !== null
            && $item->status !== PlanItemStatus::Cancelled
            && ! ($plan->hasSchedule() && $item->in_payment_schedule));
        $charges = Charge::query()->valid()->whereIn('treatment_plan_item_id', $perSession->modelKeys())->get();

        $total = $perSession->sum(fn (TreatmentPlanItem $item) => $item->subtotal());
        $owed = $charges->sum(fn (Charge $charge) => $unpaid->get($charge->getKey())['due'] ?? 0);
        $paid = $charges->sum(fn (Charge $charge) => $charge->net()) - $owed;
        $next = null;
        $late = false;

        if ($plan->hasSchedule()) {
            $states = collect($this->installmentStates($plan));
            $total += $states->sum(fn (array $row) => $row['installment']->net());
            $paid += $states->sum(fn (array $row) => $row['installment']->charge_id !== null ? $row['installment']->net() - $row['due'] : 0);
            $owed += $states->whereIn('state', ['due', 'late'])->sum('due');
            $next = $states->firstWhere('state', 'upcoming');
            $late = $states->contains('state', 'late');
        }

        return [
            'total' => round($total, 2),
            'paid' => round($paid, 2),
            'owed' => round($owed, 2),
            'remaining' => max(0, round($total - $paid, 2)),
            'late' => $late,
            'next' => $next ? ['installment' => $next['installment'], 'due' => $next['due']] : null,
        ];
    }

    /**
     * Los pagos del cronograma de los planes vigentes que están en esos estados ('late': la lista para
     * llamar; 'due' y 'upcoming' hasta una fecha: lo que vence esta semana), con lo que falta pagar de cada uno.
     *
     * @param  list<'paid'|'due'|'late'|'upcoming'>  $states
     * @return array<int, float> Lo que falta pagar, por id del pago.
     */
    public function installmentsIn(array $states, ?CarbonInterface $dueUntil = null): array
    {
        // Lo vencido sin pagar solo lo tienen los pacientes que deben algo, o los que tienen una cuota vencida que aún no se cargó.
        $onlyOwed = array_diff($states, ['due', 'late']) === [];

        $plans = TreatmentPlan::query()
            ->whereNot('status', TreatmentPlanStatus::Cancelled)
            ->whereHas('installments', fn ($query) => $query->when($dueUntil, fn ($query) => $query->whereDate('due_on', '<=', $dueUntil)))
            ->when($onlyOwed, fn ($query) => $query->where(fn ($query) => $query
                ->whereHas('patient', fn ($patient) => $patient->owing())
                ->orWhereHas('installments', fn ($installments) => $installments->whereNull('charge_id')->whereDate('due_on', '<=', today()))))
            ->with('patient')
            ->get();
        $due = [];

        foreach ($plans as $plan) {
            foreach ($this->installmentStates($plan) as ['installment' => $installment, 'state' => $state, 'due' => $amount]) {
                if (in_array($state, $states, true) && ($dueUntil === null || $installment->due_on->lte($dueUntil))) {
                    $due[$installment->getKey()] = $amount;
                }
            }
        }

        return $due;
    }

    /**
     * Lo que se espera cobrar el día de la cita: el saldo pendiente, las cuotas que vencen hasta ese día
     * y la sesión o el tratamiento de la cita (o "incluida" si ya se paga con el plan).
     *
     * @return array{lines: list<array{label: string, amount: float|null}>, total: float}
     */
    public function estimateFor(Appointment $appointment, bool $withTooth = false): array
    {
        $appointment->loadMissing(['patient', 'treatment', 'campaign', 'planItem.plan.installments', 'planItem.plan.includedIn.patient']);
        $patient = $appointment->patient;
        $lines = [];

        $balance = $patient->balance();

        if ($balance > 0) {
            $owed = $this->unpaidCharges($patient)->map(fn (array $row) => $row['charge']->description);
            $detail = $owed->take(3)->implode(', ').($owed->count() > 3 ? ' y '.($owed->count() - 3).' más' : '');

            $lines[] = ['label' => 'Saldo pendiente'.($detail !== '' ? " ({$detail})" : ''), 'amount' => $balance];
        } elseif ($balance < 0) {
            $lines[] = ['label' => 'Tiene a favor', 'amount' => $balance];
        }

        $upcoming = TreatmentPlanInstallment::query()
            ->whereNull('charge_id')
            ->whereDate('due_on', '<=', $appointment->starts_at)
            ->whereHas('plan', fn ($query) => $query->where('patient_id', $patient->getKey())->whereNot('status', TreatmentPlanStatus::Cancelled))
            ->with('plan.installments')
            ->orderBy('due_on')
            ->get();

        foreach ($upcoming as $installment) {
            $lines[] = [
                'label' => $installment->label().' · '.($installment->plan->title ?: 'Plan de tratamiento').' · vence '.$installment->due_on->format('d/m'),
                'amount' => $installment->net(),
            ];
        }

        $item = $appointment->planItem;

        if ($item) {
            $session = min($item->sessions_done + 1, max(1, $item->sessions_planned));
            $label = $item->label($withTooth).($item->sessions_planned > 1 ? " · sesión {$session} de {$item->sessions_planned}" : '');

            $lines[] = match (true) {
                $item->in_payment_schedule => ['label' => $label.' · incluida en el plan ('.mb_strtolower($item->plan->payment_mode->getLabel()).')', 'amount' => null],
                // Lo del paquete en un 2x1 lo paga la otra persona; lo que se agregue aparte sí se cobra.
                $item->plan->included_in_plan_id !== null && $item->treatment_package_id !== null && $item->subtotal() <= 0 => ['label' => $label.' · incluida en el 2x1 de '.$item->plan->includedIn?->patient?->full_name, 'amount' => null],
                default => ['label' => $label, 'amount' => $this->nextSession($item)[0]],
            };
        } elseif ((float) $appointment->treatment?->price_from > 0) {
            $price = (float) $appointment->treatment->price_from;
            $discount = $appointment->campaign?->discountOn($price, $appointment->treatment_id) ?? 0.0;

            $lines[] = [
                'label' => $appointment->treatment->name.($discount > 0 ? ' · precio normal '.Money::format($price).', con '.$appointment->campaign->name : ''),
                'amount' => round($price - $discount, 2),
            ];
        }

        return [
            'lines' => $lines,
            'total' => max(0, round(collect($lines)->sum(fn (array $line) => (float) $line['amount']), 2)),
        ];
    }

    /**
     * La parte del precio acordado que carga la próxima sesión y su número. La última carga lo que falta.
     *
     * @return array{0: float, 1: int}
     */
    private function nextSession(TreatmentPlanItem $item): array
    {
        $total = $item->subtotal();
        $previous = Charge::query()->valid()->where('treatment_plan_item_id', $item->getKey())->get(['amount', 'discount']);
        $remaining = round($total - $previous->sum(fn (Charge $c) => $c->net()), 2);
        $sessions = max(1, (int) $item->sessions_planned);
        $session = $previous->count() + 1;

        return [max(0, $session >= $sessions ? $remaining : min($remaining, round($total / $sessions, 2))), $session];
    }

    public function void(Charge $charge, User $by, string $reason): void
    {
        $charge->update([
            'voided_at' => now(),
            'voided_by_user_id' => $by->getKey(),
            'void_reason' => $reason,
        ]);
    }

    /** @return Collection<int, Charge> Lo cargado por la cita, sin anulados. */
    public function chargesFor(Appointment $appointment): Collection
    {
        return Charge::query()->valid()->whereBelongsTo($appointment)->orderBy('id')->get();
    }

    /**
     * Al cobrar, recepción revisa lo de hoy: corrige montos, agrega o quita conceptos.
     *
     * @param  list<array{id?: ?int, description: string, amount: float|string, discount?: float|string|null}>  $lines
     */
    public function reviseCharges(Appointment $appointment, array $lines, User $by): void
    {
        DB::transaction(function () use ($appointment, $lines, $by) {
            $current = $this->chargesFor($appointment)->keyBy('id');
            $kept = [];

            foreach ($lines as $line) {
                $charge = filled($line['id'] ?? null) ? $current->get((int) $line['id']) : null;
                $values = [
                    'description' => trim($line['description']),
                    'amount' => round((float) $line['amount'], 2),
                    'discount' => round((float) ($line['discount'] ?? 0), 2),
                ];

                if ($charge) {
                    $kept[] = $charge->getKey();
                    $charge->fill($values + [
                        'discount_reason' => $values['discount'] > 0 ? ($charge->discount_reason ?? self::CHECKOUT_DISCOUNT) : null,
                    ])->save();
                } else {
                    $this->addCharge($appointment->patient, $values + ['discount_reason' => self::CHECKOUT_DISCOUNT], $by, $appointment);
                }
            }

            $current->except($kept)->each(fn (Charge $c) => $this->void($c, $by, 'Quitado al cobrar'));
        });
    }

    /**
     * Pago recibido en el consultorio.
     *
     * @param  array{amount: float|string, method: PaymentMethod|string, reference?: ?string, notes?: ?string, paid_at?: mixed}  $data
     */
    public function registerPayment(Patient $patient, array $data, User $by, ?Appointment $appointment = null): Payment
    {
        return Payment::create([
            'patient_id' => $patient->getKey(),
            'appointment_id' => $appointment?->getKey(),
            'doctor_id' => $appointment?->doctor_id,
            'treatment_id' => $appointment?->treatment_id,
            'amount' => round((float) $data['amount'], 2),
            'method' => $data['method'],
            'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'] ?? now(),
            'received_by_user_id' => $by->getKey(),
        ]);
    }

    private static function key(Charge $charge): string
    {
        return $charge->treatment_plan_item_id ? 'item-'.$charge->treatment_plan_item_id : 'treatment-'.$charge->treatment_id;
    }
}
