<?php

namespace App\Models;

use App\Enums\PlanItemStatus;
use App\Exceptions\ClinicalException;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Procedimiento del plan, con su costo y sesiones. Una vez aceptado, su precio ya no cambia. */
#[Fillable(['treatment_plan_id', 'treatment_id', 'treatment_package_id', 'description', 'tooth', 'surfaces', 'quantity', 'unit_price', 'discount', 'sessions_planned', 'sessions_done', 'status', 'accepted_at', 'in_payment_schedule', 'sort_order'])]
class TreatmentPlanItem extends Model
{
    use Auditable;

    /** Lo que el paciente firmó no se cambia ni se quita: se cancela y se agrega el correcto. */
    public const AGREED_FIELDS = ['treatment_id', 'description', 'quantity', 'unit_price', 'discount'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'status' => PlanItemStatus::class,
            'accepted_at' => 'datetime',
            'in_payment_schedule' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TreatmentPlanItem $item) {
            if ($item->getOriginal('accepted_at') !== null && $item->isDirty(self::AGREED_FIELDS)) {
                throw ClinicalException::agreedPrice();
            }
        });

        static::deleting(function (TreatmentPlanItem $item) {
            if ($item->getOriginal('accepted_at') !== null) {
                throw ClinicalException::agreedPrice();
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    /** El paquete de donde vino, si vino de uno. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TreatmentPackage::class, 'treatment_package_id')->withTrashed();
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Atenciones en las que se trabajó este procedimiento. */
    public function consultations(): BelongsToMany
    {
        return $this->belongsToMany(Consultation::class, 'consultation_plan_items')->withTimestamps();
    }

    public function subtotal(): float
    {
        return round($this->quantity * (float) $this->unit_price - (float) $this->discount, 2);
    }

    /** "Curación con resina · pieza 36 (MO)". */
    public function label(bool $withTooth = true): string
    {
        return $this->description.($withTooth && filled($this->tooth) ? ' · pieza '.$this->tooth.(filled($this->surfaces) ? " ({$this->surfaces})" : '') : '');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [PlanItemStatus::Pending, PlanItemStatus::InProgress], true);
    }
}
