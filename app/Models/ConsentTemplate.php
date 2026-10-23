<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Texto base de un consentimiento informado. */
#[Fillable(['name', 'treatment_id', 'body', 'version', 'is_active'])]
class ConsentTemplate extends Model
{
    use Auditable;

    /** Cada cambio del texto es una versión nueva; lo ya firmado guarda su propio texto. */
    protected static function booted(): void
    {
        static::updating(function (ConsentTemplate $template) {
            if ($template->isDirty('body')) {
                $template->version = $template->getOriginal('version') + 1;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
