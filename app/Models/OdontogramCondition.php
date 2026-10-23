<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Hallazgo del catálogo del odontograma (norma técnica del MINSA). */
#[Fillable(['code', 'name', 'abbreviation', 'applies_to', 'abbreviations', 'allows_good_state', 'allows_bad_state', 'sort_order', 'is_active'])]
class OdontogramCondition extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'abbreviations' => 'array',
            'allows_good_state' => 'boolean',
            'allows_bad_state' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
