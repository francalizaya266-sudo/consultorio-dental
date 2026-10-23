<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso en la bandeja de WhatsApp: recepción lo envía con un clic o, con la API, sale solo. */
#[Fillable(['account_id', 'patient_id', 'appointment_id', 'template_key', 'phone', 'params', 'status', 'channel', 'due_at', 'opened_at', 'opened_by_user_id', 'sent_at', 'sent_by_user_id', 'provider_message_id', 'error'])]
class WhatsappMessage extends Model
{
    protected function casts(): array
    {
        return [
            'template_key' => MessageTemplateKey::class,
            'params' => 'array',
            'status' => MessageStatus::class,
            'channel' => MessageChannel::class,
            'due_at' => 'datetime',
            'opened_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [MessageStatus::Pending, MessageStatus::Opened]);
    }

    /** Avisos que ya toca enviar (los recordatorios esperan a su día). */
    public function scopeDue(Builder $query): Builder
    {
        return $query->pending()->where(fn (Builder $q) => $q->whereNull('due_at')->orWhere('due_at', '<=', now()));
    }
}
