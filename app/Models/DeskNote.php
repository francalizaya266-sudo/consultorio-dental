<?php

namespace App\Models;

use Database\Factories\DeskNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendiente de recepción: algo que hay que acordarse de hacer un día ("llamar al laboratorio por la corona de
 * Rosa", "el viernes viene el técnico del sillón"). Sale en "Por hacer hoy" el día que toca y se queda ahí hasta
 * que alguien lo marca como hecho, así el turno que sigue sabe qué quedó.
 */
#[Fillable(['body', 'due_on', 'patient_id', 'done_at', 'done_by_user_id', 'created_by_user_id'])]
class DeskNote extends Model
{
    /** @use HasFactory<DeskNoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_at' => 'datetime',
        ];
    }

    /** El paciente del que trata, si trata de uno. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_user_id');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /** Lo que toca ver hoy: lo que falta de hoy o de antes, y lo que ya se hizo hoy (para el turno que sigue). */
    #[Scope]
    protected function forToday(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $open) => $open->whereNull('done_at')->whereDate('due_on', '<=', today()))
            ->orWhere('done_at', '>=', today()));
    }

    /** "Para hoy", "Desde el lun. 9 nov." o "Hecho por Ana a las 10:30". */
    public function status(): string
    {
        return match (true) {
            $this->isDone() => 'Hecho'.($this->doneBy ? ' por '.$this->doneBy->name : '').' a las '.$this->done_at->format('H:i'),
            $this->due_on->isToday() => 'Para hoy',
            default => 'Desde el '.$this->due_on->translatedFormat('D j M'),
        };
    }
}
