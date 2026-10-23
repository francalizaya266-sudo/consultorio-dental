<?php

namespace App\Models;

use App\Enums\ScheduleBlockType;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Horario bloqueado (almuerzo, curso, vacaciones). Sin doctor aplica a todo el consultorio. */
#[Fillable(['doctor_id', 'type', 'starts_at', 'ends_at', 'reason', 'created_by_user_id'])]
class ScheduleBlock extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'type' => ScheduleBlockType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Bloqueos del doctor o de todo el consultorio que se cruzan con el rango. */
    public function scopeAffecting(Builder $query, Doctor $doctor, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $doctor->getKey()))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from);
    }
}
