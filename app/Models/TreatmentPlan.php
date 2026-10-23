<?php

namespace App\Models;

use App\Enums\PlanItemStatus;
use App\Enums\TreatmentPlanStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** Plan de tratamiento con costos (presupuesto informativo). */
#[Fillable(['patient_id', 'doctor_id', 'consultation_id', 'title', 'status', 'notes', 'accepted_at', 'completed_at'])]
class TreatmentPlan extends Model
{
    use Auditable, SoftDeletes;

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
        return $this->hasMany(TreatmentPlanItem::class, 'treatment_plan_id');
    }

    public function total(): float
    {
        return round((float) $this->items()
            ->whereNot('status', PlanItemStatus::Cancelled)
            ->sum(DB::raw('quantity * unit_price - discount')), 2);
    }
}
