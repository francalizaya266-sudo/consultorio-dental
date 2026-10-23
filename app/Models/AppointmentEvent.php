<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hecho en la línea de tiempo de una cita (creada, confirmada, movida, cancelada…). */
#[Fillable(['appointment_id', 'type', 'actor', 'user_id', 'from_starts_at', 'to_starts_at', 'notes', 'meta'])]
class AppointmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_starts_at' => 'datetime',
            'to_starts_at' => 'datetime',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
