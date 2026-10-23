<?php

namespace App\Models;

use App\Enums\Dentition;
use App\Enums\OdontogramType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** Versión del odontograma de un paciente (una por consulta). */
#[Fillable(['patient_id', 'consultation_id', 'doctor_id', 'type', 'dentition', 'specifications', 'observations'])]
class Odontogram extends Model
{
    use SoftDeletes, Auditable;

    protected function casts(): array
    {
        return [
            'type' => OdontogramType::class,
            'dentition' => Dentition::class,
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(OdontogramEntry::class);
    }

    /** Nueva versión que parte de esta, para registrar la evolución en otra consulta. */
    public function copyForConsultation(Consultation $consultation): self
    {
        return DB::transaction(function () use ($consultation) {
            $copy = $this->replicate(['consultation_id', 'doctor_id', 'type']);
            $copy->fill([
                'consultation_id' => $consultation->getKey(),
                'doctor_id' => $consultation->doctor_id,
                'type' => OdontogramType::Evolution,
            ])->save();

            foreach ($this->entries as $entry) {
                $copy->entries()->create($entry->only([
                    'tooth', 'surface', 'odontogram_condition_id', 'state', 'abbreviation', 'to_tooth', 'notes',
                ]));
            }

            return $copy;
        });
    }
}
