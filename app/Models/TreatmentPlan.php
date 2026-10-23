<?php

namespace App\Models;

use App\Enums\PlanItemStatus;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/** Plan de tratamiento con costos (presupuesto informativo). */
#[Fillable(['patient_id', 'doctor_id', 'consultation_id', 'included_in_plan_id', 'campaign_id', 'title', 'status', 'payment_mode', 'notes', 'accepted_at', 'completed_at'])]
class TreatmentPlan extends Model
{
    use Auditable, SoftDeletes;

    /** Planes que siguen vigentes: se pueden presentar, aceptar o trabajar. */
    public const OPEN_STATUSES = [TreatmentPlanStatus::Draft, TreatmentPlanStatus::Proposed, TreatmentPlanStatus::Accepted, TreatmentPlanStatus::InProgress];

    protected function casts(): array
    {
        return [
            'status' => TreatmentPlanStatus::class,
            'payment_mode' => PlanPaymentMode::class,
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** En un 2x1, el plan de quien paga; este plan no tiene costo. */
    public function includedIn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'included_in_plan_id')->withTrashed();
    }

    /** La campaña por la que vino, si el plan se armó con su paquete. */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withTrashed();
    }

    /** En un 2x1, el plan de la otra persona, que va incluido en este. */
    public function companionPlans(): HasMany
    {
        return $this->hasMany(self::class, 'included_in_plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class, 'treatment_plan_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Cronograma de pagos: la inicial o el pago único, y las cuotas. */
    public function installments(): HasMany
    {
        return $this->hasMany(TreatmentPlanInstallment::class, 'treatment_plan_id')->orderBy('number')->chaperone('plan');
    }

    /** Cada vez que el paciente firmó: al aceptarlo y al aceptar lo que se agregó. */
    public function signatures(): HasMany
    {
        return $this->hasMany(TreatmentPlanSignature::class, 'treatment_plan_id')->orderBy('signed_at');
    }

    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Total del presupuesto sin los procedimientos cancelados. */
    public function total(): float
    {
        return round($this->items
            ->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)
            ->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2);
    }

    /** Lo aceptado se paga con cronograma (pago único o cuotas), no sesión por sesión. */
    public function hasSchedule(): bool
    {
        return $this->payment_mode?->hasSchedule() ?? false;
    }

    /** Procedimientos agregados después de aceptar el plan, que el paciente todavía no firmó. */
    public function itemsToAccept(): Collection
    {
        return $this->items->filter(fn (TreatmentPlanItem $item) => $item->accepted_at === null && $item->status !== PlanItemStatus::Cancelled)->values();
    }

    /** El paquete con que se armó el plan: de él sale la forma de pago que se ofrece al firmar. */
    public function offeredPackage(): ?TreatmentPackage
    {
        if ($this->included_in_plan_id !== null) {
            return null;
        }

        $this->loadMissing('items.package');

        return $this->items->first(fn (TreatmentPlanItem $item) => $item->treatment_package_id !== null && $item->status !== PlanItemStatus::Cancelled)?->package;
    }

    /**
     * Cada paquete del plan con su precio normal, lo que cuesta en el paquete y lo que ahorra.
     *
     * @return list<array{name: string, normal: float, price: float, savings: float}>
     */
    public function packageSummaries(): array
    {
        // En el plan que va incluido en un 2x1 no hay precio de paquete que mostrar.
        if ($this->included_in_plan_id !== null) {
            return [];
        }

        $this->loadMissing('items.package');

        return $this->items
            ->filter(fn (TreatmentPlanItem $item) => $item->treatment_package_id !== null && $item->status !== PlanItemStatus::Cancelled)
            ->groupBy('treatment_package_id')
            ->map(fn (Collection $items) => [
                'name' => $items->first()->package->name,
                'normal' => round($items->sum(fn (TreatmentPlanItem $item) => $item->quantity * (float) $item->unit_price), 2),
                'price' => round($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2),
                'savings' => round($items->sum(fn (TreatmentPlanItem $item) => (float) $item->discount), 2),
            ])
            ->values()
            ->all();
    }

    /** El paquete 2x1 del plan, si tiene uno: con el mismo pago se atiende también a otra persona. */
    public function twoForOnePackage(): ?TreatmentPackage
    {
        if ($this->included_in_plan_id !== null) {
            return null;
        }

        $this->loadMissing('items.package');

        return $this->items->first(fn (TreatmentPlanItem $item) => $item->package?->for_two && $item->status !== PlanItemStatus::Cancelled)?->package;
    }

    /** En un 2x1, a quién más se atiende con este plan: "Ana Pérez". Null si no tiene. */
    public function companionNames(): ?string
    {
        $this->loadMissing('companionPlans.patient');

        $names = $this->companionPlans
            ->reject(fn (TreatmentPlan $plan) => $plan->status === TreatmentPlanStatus::Cancelled)
            ->map(fn (TreatmentPlan $plan) => $plan->patient->full_name);

        return $names->isEmpty() ? null : $names->join(', ', ' y ');
    }
}
