<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Money;
use Database\Factories\TreatmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
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
    use Auditable, HasFactory, SoftDeletes;

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

    public function smileCases(): HasMany
    {
        return $this->hasMany(SmileCase::class);
    }

    /** Precio para la web: "desde S/ 150", "Gratis" o null si no se muestra. */
    public function webPrice(): ?string
    {
        if (! $this->show_price || $this->price_from === null) {
            return null;
        }

        return (float) $this->price_from === 0.0 ? 'Gratis' : 'desde '.Money::short($this->price_from);
    }

    /** "1 sesión", "1 – 3 sesiones" o el texto tal cual ("Una vez al mes"). */
    public function sessionsText(): ?string
    {
        $label = trim((string) $this->sessions_label);

        if ($label === '') {
            return null;
        }

        return match (true) {
            ctype_digit($label) => $label.((int) $label === 1 ? ' sesión' : ' sesiones'),
            (bool) preg_match('/^\d+\s*[–-]\s*\d+$/u', $label) => preg_replace('/\s*[–-]\s*/u', ' – ', $label).' sesiones',
            default => $label,
        };
    }

    /** Minutos de limpieza y esterilización después de la cita. */
    public function bufferMinutes(): int
    {
        return $this->buffer_minutes ?? (int) Setting::get('agenda.buffer_minutes', 10);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Lo que el paciente puede reservar solo desde la web (primera cita). */
    #[Scope]
    protected function onlineBookable(Builder $query): Builder
    {
        return $query->active()->where('online_bookable', true);
    }
}
