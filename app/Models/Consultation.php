<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ConsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Atención clínica: motivo, examen, diagnóstico, evolución e indicaciones. */
#[Fillable(['patient_id', 'doctor_id', 'appointment_id', 'attended_at', 'chief_complaint', 'current_illness', 'clinical_exam', 'vital_signs', 'diagnosis_notes', 'procedures', 'indications', 'next_steps', 'closed_at'])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use HasFactory, SoftDeletes, Auditable;

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
            'vital_signs' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(ConsultationDiagnosis::class);
    }

    public function odontogram(): HasOne
    {
        return $this->hasOne(Odontogram::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ClinicalFile::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }
}
