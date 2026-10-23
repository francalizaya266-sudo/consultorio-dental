<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Código de diagnóstico CIE-10. */
#[Fillable(['code', 'description', 'is_dental'])]
class Icd10Code extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_dental' => 'boolean',
        ];
    }

    #[Scope]
    protected function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('code', 'like', "{$term}%")->orWhere('description', 'like', "%{$term}%"));
    }
}
