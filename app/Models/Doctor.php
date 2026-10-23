<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Perfil del doctor: datos públicos, horario y agenda. */
#[Fillable(['user_id', 'name', 'slug', 'specialty', 'cop_number', 'rne_number', 'bio', 'education', 'photo_path', 'agenda_color', 'is_public', 'accepts_online_booking', 'sort_order', 'is_active'])]
class Doctor extends Model
{
    /** @use HasFactory<DoctorFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'accepts_online_booking' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function treatments(): BelongsToMany
    {
        return $this->belongsToMany(Treatment::class, 'doctor_treatment')->withPivot('duration_minutes')->withTimestamps();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    public function scheduleBlocks(): HasMany
    {
        return $this->hasMany(ScheduleBlock::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Duración del tratamiento para este doctor (o la general del tratamiento). */
    public function durationFor(Treatment $treatment): int
    {
        $custom = $this->treatments()
            ->whereKey($treatment->getKey())
            ->first()?->pivot?->duration_minutes;

        return (int) ($custom ?? $treatment->duration_minutes);
    }

    /** Con un solo doctor activo, la columna, el filtro y las tablas "por doctor" sobran. */
    public static function severalActive(): bool
    {
        return once(fn () => static::query()->active()->count() > 1);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
