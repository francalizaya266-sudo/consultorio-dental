<?php

namespace App\Models;

use App\Enums\DiagnosisType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Diagnóstico de una consulta, opcionalmente por pieza dental. */
#[Fillable(['consultation_id', 'icd10_code_id', 'description', 'tooth', 'type'])]
class ConsultationDiagnosis extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'type' => DiagnosisType::class,
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function icd10Code(): BelongsTo
    {
        return $this->belongsTo(Icd10Code::class);
    }
}
