<?php

namespace App\Models;

use App\Enums\TreatmentKind;
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
#[Fillable(['name', 'slug', 'summary', 'description', 'price_from', 'show_price', 'duration_minutes', 'buffer_minutes', 'sessions_label', 'checkup_months', 'next_session_days', 'before_instructions', 'aftercare', 'is_evaluation', 'online_bookable', 'is_public', 'image_path', 'seo_title', 'seo_description', 'sort_order', 'is_active'])]
class Treatment extends Model
{
    /** @use HasFactory<TreatmentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price_from' => 'decimal:2',
            'checkup_months' => 'integer',
            'next_session_days' => 'integer',
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

    /** La receta: los materiales que usa en una atención y cuánto de cada uno. */
    public function supplies(): BelongsToMany
    {
        return $this->belongsToMany(Supply::class, 'treatment_supplies')->withPivot('quantity')->withTimestamps();
    }

    public function clinicalTemplates(): HasMany
    {
        return $this->hasMany(ClinicalTemplate::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** Atenciones en que se hizo fuera del plan. */
    public function consultations(): BelongsToMany
    {
        return $this->belongsToMany(Consultation::class, 'consultation_treatments')->withTimestamps();
    }

    /** Ya se agendó, se hizo, se presupuestó o se cobró: las citas y la caja lo nombran, así que no se elimina. */
    public function hasHistory(): bool
    {
        return $this->appointments()->exists()
            || $this->consultations()->exists()
            || collect([TreatmentPlanItem::class, TreatmentPackageItem::class, Charge::class, Payment::class])
                ->contains(fn (string $model) => $model::query()->where('treatment_id', $this->getKey())->exists());
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

    /** Cada cuántos meses vuelve a control si esto fue lo último que se le hizo: lo que diga su ficha o, si no dice, lo usual según su nombre. */
    public function checkupMonths(): int
    {
        return $this->checkup_months ?: TreatmentKind::for($this)->checkupMonths();
    }

    /** A cuántos días se agenda la sesión siguiente: lo que diga su ficha o, si no dice, lo usual según su nombre. */
    public function nextSessionDays(): int
    {
        return $this->next_session_days ?: TreatmentKind::for($this)->nextSessionDays();
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
