<?php

namespace App\Models;

use App\Enums\ToothState;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Marca en una pieza o superficie: rojo = por tratar, azul = tratado. */
#[Fillable(['odontogram_id', 'tooth', 'surface', 'odontogram_condition_id', 'state', 'abbreviation', 'to_tooth', 'notes'])]
class OdontogramEntry extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'tooth' => 'integer',
            'to_tooth' => 'integer',
            'state' => ToothState::class,
        ];
    }

    public function odontogram(): BelongsTo
    {
        return $this->belongsTo(Odontogram::class);
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(OdontogramCondition::class, 'odontogram_condition_id');
    }
}
