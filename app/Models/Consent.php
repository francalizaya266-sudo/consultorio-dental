<?php

namespace App\Models;

use App\Enums\PatientRelationship;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Consentimiento informado firmado (se guarda el texto tal como se firmó). */
#[Fillable(['patient_id', 'consent_template_id', 'treatment_id', 'doctor_id', 'title', 'body_snapshot', 'signer_name', 'signer_document', 'signer_relationship', 'signature_path', 'clinical_file_id', 'signed_at', 'revoked_at'])]
class Consent extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'signer_relationship' => PatientRelationship::class,
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConsentTemplate::class, 'consent_template_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ClinicalFile::class, 'clinical_file_id');
    }
}
