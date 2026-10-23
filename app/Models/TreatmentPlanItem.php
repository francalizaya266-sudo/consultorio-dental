<?php

namespace App\Models;

use App\Enums\PlanItemStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Procedimiento del plan, con su costo y sesiones. */
#[Fillable(['treatment_plan_id', 'treatment_id', 'description', 'tooth', 'surfaces', 'quantity', 'unit_price', 'discount', 'sessions_planned', 'sessions_done', 'status', 'sort_order'])]
class TreatmentPlanItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'status' => PlanItemStatus::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
