<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cargos y pagos. Lo realizado se carga solo al terminar la atención (o al marcar la cita
 * como atendida): la sesión del procedimiento del plan, con el precio acordado, o el
 * tratamiento de la cita con su precio y la promoción. Recepción lo revisa al cobrar.
 * Saldo = cargos − pagos, sin los anulados. Nada se borra: se anula con motivo.
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

            if ($appointment && $appointment->treatment_plan_item_id === null) {
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
        $total = $item->subtotal();
        $previous = Charge::query()->valid()->where('treatment_plan_item_id', $item->getKey())->get(['amount', 'discount']);
        $remaining = round($total - $previous->sum(fn (Charge $c) => $c->net()), 2);

        if ($remaining <= 0) {
            return null;
        }

        $sessions = max(1, (int) $item->sessions_planned);
        $session = $previous->count() + 1;
        $amount = $session >= $sessions ? $remaining : min($remaining, round($total / $sessions, 2));

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
