<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\TreatmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tratamiento o tipo de cita, con duración y precio referencial. */
#[Fillable(['name', 'slug', 'summary', 'description', 'price_from', 'show_price', 'duration_minutes', 'buffer_minutes', 'sessions_label', 'is_evaluation', 'online_bookable', 'is_public', 'image_path', 'seo_title', 'seo_description', 'sort_order', 'is_active'])]
class Treatment extends Model
{
    /** @use HasFactory<TreatmentFactory> */
    use HasFactory, SoftDeletes, Auditable;

    protected function casts(): array
    {
        return [
            'price_from' => 'decimal:2',
            'show_price' => 'boolean',
            'is_evaluation' => 'boolean',
            'online_bookable' => 'boolean',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_treatment')->withPivot('duration_minutes')->withTimestamps();
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function clinicalTemplates(): HasMany
    {
        return $this->hasMany(ClinicalTemplate::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** Minutos de limpieza y esterilización después de la cita. */
    public function bufferMinutes(): int
    {
        return $this->buffer_minutes ?? (int) Setting::get('agenda.buffer_minutes', 10);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Lo que el paciente puede reservar solo desde la web (primera cita). */
    public function scopeOnlineBookable(Builder $query): Builder
    {
        return $query->active()->where('online_bookable', true);
    }
}
