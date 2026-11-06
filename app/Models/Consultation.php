<?php

namespace App\Models;

use App\Enums\TreatmentKind;
use App\Enums\VisitType;
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
#[Fillable(['patient_id', 'doctor_id', 'appointment_id', 'attended_at', 'chief_complaint', 'current_illness', 'clinical_exam', 'vital_signs', 'diagnosis_notes', 'procedures', 'procedure_details', 'indications', 'next_steps', 'prices', 'closed_at'])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
            'vital_signs' => 'array',
            'procedure_details' => 'array',
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

        // Consulta aparte (una vez por pedido): no deja cargada la relación, que el cobro y el plan leen después de cambiarla.
        return once(fn () => $this->planItems()
            ->with('plan.consultation')
            ->get()
            ->filter(fn (TreatmentPlanItem $item) => $item->plan->consultation_id !== $this->getKey())
            ->values());
    }

    /** El tratamiento por el que viene: el de la cita o, sin cita, el primero que se trabajó. */
    public function mainTreatment(): ?Treatment
    {
        return $this->appointment?->treatment
            ?? $this->planItems()->with('treatment')->first()?->treatment
            ?? $this->treatments()->first();
    }

    /** Por qué viene: evaluación, sesión de un plan, control, urgencia o primera vez por esto. */
    public function visitType(): VisitType
    {
        $treatment = $this->appointment?->treatment;

        return match (true) {
            (bool) $treatment?->is_evaluation => VisitType::Evaluation,
            $this->plannedWork()->isNotEmpty() => VisitType::PlanSession,
            TreatmentKind::isControl($treatment) => VisitType::Control,
            $this->appointment_id === null || TreatmentKind::isUrgent($treatment) => VisitType::Urgent,
            default => VisitType::FirstVisit,
        };
    }

    /** Qué datos propios lleva la evolución. Un control solo lleva los de la ortodoncia (en los demás no se trabaja). */
    public function procedureKind(): TreatmentKind
    {
        $kind = TreatmentKind::for($this->mainTreatment());

        return $this->visitType() === VisitType::Control && $kind !== TreatmentKind::Orthodontics ? TreatmentKind::Other : $kind;
    }

    /** "PA 120/80 · Pulso 72 lpm · 36.5 °C · Dolor 7/10 · Peso 18 kg". */
    public function vitalsSummary(): ?string
    {
        $vitals = $this->vital_signs ?? [];

        return collect([
            filled($vitals['blood_pressure'] ?? null) ? 'PA '.$vitals['blood_pressure'] : null,
            filled($vitals['heart_rate'] ?? null) ? 'Pulso '.$vitals['heart_rate'].' lpm' : null,
            filled($vitals['temperature'] ?? null) ? $vitals['temperature'].' °C' : null,
            filled($vitals['pain'] ?? null) ? 'Dolor '.$vitals['pain'].'/10' : null,
            filled($vitals['weight'] ?? null) ? 'Peso '.$vitals['weight'].' kg' : null,
        ])->filter()->implode(' · ') ?: null;
    }

    /** Los datos propios del tratamiento en una línea: "Pieza 36 · Caras MO · Color A2". */
    public function procedureSummary(): ?string
    {
        return $this->procedureKind()->summary($this->procedure_details);
    }
}
