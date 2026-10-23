<?php

namespace App\Models;

use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Opción rápida o atajo de texto. Sin usuario = compartida por todo el equipo. */
#[Fillable(['user_id', 'field', 'label', 'body', 'shortcut', 'status'])]
class TextSnippet extends Model
{
    protected function casts(): array
    {
        return [
            'field' => SnippetField::class,
            'status' => SnippetStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(TextSnippetUsage::class);
    }

    /** Opciones que ve un doctor en un campo: compartidas + personales, las más usadas primero. */
    #[Scope]
    protected function availableFor(Builder $query, User $user, SnippetField $field): Builder
    {
        return $query
            ->where('status', SnippetStatus::Active)
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->getKey()))
            ->whereIn('field', [$field, SnippetField::Any])
            ->addSelect([
                'my_usage' => TextSnippetUsage::select('count')
                    ->whereColumn('text_snippet_id', 'text_snippets.id')
                    ->where('user_id', $user->getKey())
                    ->limit(1),
            ])
            ->orderByDesc('my_usage')
            ->orderBy('label');
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    public function recordUse(User $user): void
    {
        $usage = $this->usages()->firstOrCreate(['user_id' => $user->getKey()], ['count' => 0]);
        $usage->increment('count', 1, ['last_used_at' => now()]);
    }
}
