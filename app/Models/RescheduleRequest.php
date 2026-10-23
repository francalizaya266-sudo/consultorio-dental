<?php

namespace App\Models;

use App\Enums\RequestedBy;
use App\Enums\RescheduleRequestStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Solicitud de cambio de horario que aprueba recepción o el doctor (nivel 2). */
#[Fillable(['appointment_id', 'requested_by', 'requested_by_user_id', 'status', 'requested_doctor_id', 'requested_treatment_id', 'reason', 'approved_option_id', 'resolved_by_user_id', 'resolved_at', 'response_note'])]
class RescheduleRequest extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'requested_by' => RequestedBy::class,
            'status' => RescheduleRequestStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(RescheduleRequestOption::class);
    }

    public function approvedOption(): BelongsTo
    {
        return $this->belongsTo(RescheduleRequestOption::class, 'approved_option_id');
    }

    public function requestedDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'requested_doctor_id');
    }

    public function requestedTreatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'requested_treatment_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
