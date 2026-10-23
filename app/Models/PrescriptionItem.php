<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Medicamento de una receta. */
#[Fillable(['prescription_id', 'medication', 'presentation', 'dose', 'frequency', 'duration', 'instructions', 'sort_order'])]
class PrescriptionItem extends Model
{
    use Auditable;

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }
}
