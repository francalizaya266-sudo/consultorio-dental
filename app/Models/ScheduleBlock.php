<?php

namespace App\Models;

use App\Enums\ScheduleBlockType;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Horario bloqueado (descanso, curso, vacaciones). Sin doctor aplica a todo el consultorio. */
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

    /**
     * Bloquea días completos: de las 00:00 de un día a las 00:00 de otro (o hasta el último minuto, como se
     * escribían antes).
     */
    public function isWholeDays(): bool
    {
        return $this->starts_at->format('H:i') === '00:00' && in_array($this->ends_at->format('H:i'), ['00:00', '23:55', '23:59'], true);
    }

    /** El último día que alcanza: un bloqueo que termina a las 00:00 no toca ese día. */
    public function lastDay(): CarbonInterface
    {
        return $this->ends_at->copy()->subMinute()->startOfDay();
    }

    /**
     * Cuándo es, como se dice: "el lun. 16/11 (todo el día)", "del lun. 16/11 al vie. 20/11 (días completos)" o
     * "del 16/11 13:00 al 16/11 15:00".
     */
    public function span(): string
    {
        if (! $this->isWholeDays()) {
            return "del {$this->starts_at->format('d/m H:i')} al {$this->ends_at->format('d/m H:i')}";
        }

        return $this->starts_at->isSameDay($this->lastDay())
            ? 'el '.$this->starts_at->translatedFormat('D d/m').' (todo el día)'
            : 'del '.$this->starts_at->translatedFormat('D d/m').' al '.$this->lastDay()->translatedFormat('D d/m').' (días completos)';
    }

    /** Qué parte de ese día toma: "todo el día", "de 15:00 a 19:00", "desde las 15:00" o "hasta las 13:00". */
    public function hoursOn(CarbonInterface $day): string
    {
        $fromBefore = $this->starts_at->lte($day->copy()->startOfDay());
        $untilAfter = $this->ends_at->gte($day->copy()->endOfDay()->startOfMinute());

        return match (true) {
            $fromBefore && $untilAfter => 'todo el día',
            $fromBefore => 'hasta las '.$this->ends_at->format('H:i'),
            $untilAfter => 'desde las '.$this->starts_at->format('H:i'),
            default => 'de '.$this->starts_at->format('H:i').' a '.$this->ends_at->format('H:i'),
        };
    }

    /** Bloqueos del doctor o de todo el consultorio que se cruzan con el rango. */
    #[Scope]
    protected function affecting(Builder $query, Doctor $doctor, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $doctor->getKey()))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from);
    }
}
