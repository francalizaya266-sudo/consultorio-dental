<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SmileCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Caso de antes y después; se publica solo con la autorización del paciente. */
#[Fillable(['title', 'description', 'treatment_id', 'doctor_id', 'patient_id', 'before_path', 'after_path', 'consent_confirmed_at', 'consent_confirmed_by_user_id', 'is_published', 'sort_order'])]
class SmileCase extends Model
{
    /** @use HasFactory<SmileCaseFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'consent_confirmed_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function consentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_confirmed_by_user_id');
    }

    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNotNull('consent_confirmed_at')->orderBy('sort_order')->orderByDesc('id');
    }
}
