<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\Auditable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago registrado en el consultorio (efectivo, tarjeta, Yape, Plin). No se borra: se anula. */
#[Fillable(['patient_id', 'appointment_id', 'doctor_id', 'treatment_id', 'amount', 'method', 'reference', 'paid_at', 'received_by_user_id', 'notes', 'voided_at', 'voided_by_user_id', 'void_reason'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => 'datetime',
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

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
