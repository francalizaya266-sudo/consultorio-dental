<?php

namespace App\Models;

use App\Enums\MagicLinkPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Enlace personal enviado por WhatsApp. Solo se guarda el hash del token. */
#[Fillable(['account_id', 'appointment_id', 'purpose', 'token_hash', 'expires_at', 'first_used_at', 'last_used_at', 'use_count', 'revoked_at', 'created_by_user_id'])]
#[Hidden(['token_hash'])]
class MagicLink extends Model
{
    use MassPrunable;

    protected function casts(): array
    {
        return [
            'purpose' => MagicLinkPurpose::class,
            'expires_at' => 'datetime',
            'first_used_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Se eliminan 30 días después de vencer. */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(30));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
