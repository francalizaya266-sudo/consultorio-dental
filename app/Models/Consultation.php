<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ConsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Atención clínica: motivo, examen, diagnóstico, evolución e indicaciones. */
#[Fillable(['patient_id', 'doctor_id', 'appointment_id', 'attended_at', 'chief_complaint', 'current_illness', 'clinical_exam', 'vital_signs', 'diagnosis_notes', 'procedures', 'indications', 'next_steps', 'prices', 'closed_at'])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
            'vital_signs' => 'array',
            'prices' => 'array',
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

    /** La receta de esta atención (una sola, con varios medicamentos). */
    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class)->latestOfMany();
    }

    /** Procedimientos del plan de tratamiento que se trabajaron en esta atención. */
    public function planItems(): BelongsToMany
    {
        return $this->belongsToMany(TreatmentPlanItem::class, 'consultation_plan_items')->withTimestamps();
    }

    /**
     * Tratamientos que se hicieron fuera del plan: llega con lo programado en la cita y el doctor
     * lo corrige. Se cobran y descuentan sus materiales al terminar.
     */
    public function treatments(): BelongsToMany
    {
        return $this->belongsToMany(Treatment::class, 'consultation_treatments')->withTimestamps();
    }

    /**
     * Lo que viene a hacer de un plan armado antes: entonces es una sesión de ese plan, no una primera vez,
     * y el motivo y el examen ya están en la atención donde se diagnosticó. Vacío en las evaluaciones.
     *
     * @return Collection<int, TreatmentPlanItem>
     */
    public function plannedWork(): Collection
    {
        if ($this->appointment?->treatment?->is_evaluation) {
            return new Collection;
        }

        // Consulta aparte: no deja cargada la relación, que el cobro y el plan leen después de cambiarla.
        return $this->planItems()
            ->with('plan.consultation')
            ->get()
            ->filter(fn (TreatmentPlanItem $item) => $item->plan->consultation_id !== $this->getKey())
            ->values();
    }
}
