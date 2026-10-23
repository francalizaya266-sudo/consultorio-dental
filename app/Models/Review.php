<?php

namespace App\Models;

use App\Enums\ReviewSource;
use App\Models\Concerns\Auditable;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Opinión real de un paciente, copiada de Google u otra fuente para mostrarla en la web. */
#[Fillable(['author_name', 'rating', 'body', 'source', 'reviewed_on', 'url', 'treatment_id', 'is_published', 'sort_order'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'source' => ReviewSource::class,
            'reviewed_on' => 'date',
            'is_published' => 'boolean',
        ];
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->where('is_published', true)->orderBy('sort_order')->orderByDesc('reviewed_on')->orderByDesc('id');
    }

    /** "Rosa M.": en la web solo el nombre y la inicial del apellido. */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim($this->author_name)) ?: [];

        return count($parts) > 1 ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : ($parts[0] ?? '');
    }
}
