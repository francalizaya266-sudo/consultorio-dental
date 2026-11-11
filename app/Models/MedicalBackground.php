<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antecedentes médicos del paciente: alergias, enfermedades, medicamentos, embarazo. Los puede llenar el
 * propio paciente desde Mi cuenta antes de su cita ("filled_by_patient_at"); el doctor los confirma al guardarlos.
 */
#[Fillable(['patient_id', 'conditions', 'allergies', 'medications', 'surgeries', 'is_pregnant', 'pregnancy_weeks', 'is_breastfeeding', 'smoker', 'notes', 'filled_by_patient_at', 'updated_by_user_id'])]
class MedicalBackground extends Model
{
    use Auditable;

    /** Enfermedades que se marcan con un toque en la ficha. */
    public const CONDITIONS = [
        'diabetes' => 'Diabetes',
        'hypertension' => 'Hipertensión',
        'heart_disease' => 'Enfermedad cardíaca',
        'asthma' => 'Asma',
        'epilepsy' => 'Epilepsia',
        'hepatitis' => 'Hepatitis',
        'hiv' => 'VIH',
        'kidney_disease' => 'Enfermedad renal',
        'thyroid' => 'Tiroides',
        'bleeding_disorder' => 'Problemas de coagulación',
        'anesthesia_reaction' => 'Reacción a la anestesia',
        'osteoporosis_meds' => 'Toma bifosfonatos (osteoporosis)',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'is_pregnant' => 'boolean',
            'is_breastfeeding' => 'boolean',
            'smoker' => 'boolean',
            'filled_by_patient_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
