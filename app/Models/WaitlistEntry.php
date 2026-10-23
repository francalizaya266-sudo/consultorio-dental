<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paciente en lista de espera para un tratamiento o doctor. */
#[Fillable(['patient_id', 'treatment_id', 'doctor_id', 'earliest_date', 'latest_date', 'preferred_period', 'notes', 'status', 'offered_at', 'appointment_id', 'created_by_user_id'])]
class WaitlistEntry extends Model
{
    protected function casts(): array
    {
        return [
            'earliest_date' => 'date',
            'latest_date' => 'date',
            'status' => WaitlistStatus::class,
            'offered_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
