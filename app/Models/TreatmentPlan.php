<?php

namespace App\Models;

use App\Enums\PlanItemStatus;
use App\Enums\TreatmentPlanStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Plan de tratamiento con costos (presupuesto informativo). */
#[Fillable(['patient_id', 'doctor_id', 'consultation_id', 'title', 'status', 'notes', 'accepted_at', 'completed_at'])]
class TreatmentPlan extends Model
{
    use Auditable, SoftDeletes;

    /** Planes que siguen vigentes: se pueden presentar, aceptar o trabajar. */
    public const OPEN_STATUSES = [TreatmentPlanStatus::Draft, TreatmentPlanStatus::Proposed, TreatmentPlanStatus::Accepted, TreatmentPlanStatus::InProgress];

    protected function casts(): array
    {
        return [
            'status' => TreatmentPlanStatus::class,
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

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class, 'treatment_plan_id')->orderBy('sort_order')->orderBy('id');
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
}
