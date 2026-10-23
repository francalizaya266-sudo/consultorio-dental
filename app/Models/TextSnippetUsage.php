<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contador de uso de una opción por doctor. */
#[Fillable(['text_snippet_id', 'user_id', 'count', 'last_used_at'])]
class TextSnippetUsage extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function snippet(): BelongsTo
    {
        return $this->belongsTo(TextSnippet::class, 'text_snippet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
