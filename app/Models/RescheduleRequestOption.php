<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uno de los (hasta 3) horarios propuestos en una solicitud de cambio. */
#[Fillable(['reschedule_request_id', 'doctor_id', 'starts_at', 'ends_at'])]
class RescheduleRequestOption extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RescheduleRequest::class, 'reschedule_request_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
