<?php

namespace App\Models;

use App\Enums\ClinicalFileType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Radiografía, foto clínica o documento adjunto (disco privado). */
#[Fillable(['patient_id', 'consultation_id', 'type', 'title', 'disk', 'path', 'mime_type', 'size_bytes', 'taken_at', 'uploaded_by_user_id'])]
class ClinicalFile extends Model
{
    use SoftDeletes, Auditable;

    protected function casts(): array
    {
        return [
            'type' => ClinicalFileType::class,
            'taken_at' => 'date',
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

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
