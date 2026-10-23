<?php

namespace App\Models;

use App\Enums\PatientRelationship;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Firma del paciente al aceptar el plan (o lo que se le agregó), con lo que firmó tal como estaba. */
#[Fillable(['treatment_plan_id', 'signer_name', 'signer_document', 'signer_relationship', 'signature_path', 'summary', 'total', 'signed_at', 'created_by_user_id'])]
class TreatmentPlanSignature extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'signer_relationship' => PatientRelationship::class,
            'total' => 'decimal:2',
            'signed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
