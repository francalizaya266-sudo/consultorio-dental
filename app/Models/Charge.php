<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cargo al paciente por lo realizado. No se borra: se anula. */
#[Fillable(['patient_id', 'appointment_id', 'consultation_id', 'treatment_plan_item_id', 'treatment_id', 'doctor_id', 'description', 'amount', 'discount', 'discount_reason', 'campaign_id', 'charged_at', 'created_by_user_id', 'voided_at', 'voided_by_user_id', 'void_reason'])]
class Charge extends Model
{
    /** @use HasFactory<ChargeFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'charged_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlanItem::class, 'treatment_plan_item_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /** Lo que cuenta para el saldo: sin los anulados. */
    #[Scope]
    protected function valid(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /** Monto a pagar: precio menos descuento. */
    public function net(): float
    {
        return round((float) $this->amount - (float) $this->discount, 2);
    }
}
