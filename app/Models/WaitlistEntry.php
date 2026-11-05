<?php

namespace App\Models;

use App\Enums\DayPeriod;
use App\Enums\WaitlistStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paciente que quiere un horario antes (o un día que hoy está lleno). Cuando se libera un hueco
 * se le ofrece por WhatsApp; al agendarle la cita sale solo de la lista.
 */
#[Fillable(['patient_id', 'treatment_id', 'doctor_id', 'earliest_date', 'latest_date', 'preferred_period', 'notes', 'status', 'offered_at', 'offered_starts_at', 'offered_doctor_id', 'appointment_id', 'created_by_user_id'])]
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use Auditable, HasFactory;

    /** Sigue esperando: todavía no se le agendó ni se retiró. */
    public const ACTIVE_STATUSES = [WaitlistStatus::Waiting, WaitlistStatus::Offered];

    protected $attributes = [
        'status' => 'waiting',
        'preferred_period' => 'any',
    ];

    protected function casts(): array
    {
        return [
            'earliest_date' => 'date',
            'latest_date' => 'date',
            'preferred_period' => DayPeriod::class,
            'status' => WaitlistStatus::class,
            'offered_at' => 'datetime',
            'offered_starts_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** Con quién era el horario que se le ofreció. */
    public function offeredDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'offered_doctor_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Siguen esperando y su plazo no venció. */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(fn (Builder $date) => $date->whereNull('latest_date')->orWhereDate('latest_date', '>=', today()));
    }

    /** Pidieron otro día y su plazo ya pasó, o se retiraron. */
    #[Scope]
    protected function closed(Builder $query): Builder
    {
        return $query->where(fn (Builder $closed) => $closed
            ->where('status', WaitlistStatus::Removed)
            ->orWhere(fn (Builder $expired) => $expired
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->whereDate('latest_date', '<', today())));
    }

    /**
     * Pueden venir ese día (y con ese doctor, si se indica): el día cae en su plazo, no pidieron
     * a otro doctor y el doctor hace el tratamiento que quieren.
     */
    #[Scope]
    protected function availableOn(Builder $query, CarbonInterface $date, ?int $doctorId = null): Builder
    {
        return $query
            ->active()
            ->where(fn (Builder $from) => $from->whereNull('earliest_date')->orWhereDate('earliest_date', '<=', $date))
            ->where(fn (Builder $until) => $until->whereNull('latest_date')->orWhereDate('latest_date', '>=', $date))
            ->when($doctorId, fn (Builder $withDoctor) => $withDoctor
                ->where(fn (Builder $doctor) => $doctor->whereNull('doctor_id')->orWhere('doctor_id', $doctorId))
                ->where(fn (Builder $treatment) => $treatment
                    ->whereNull('treatment_id')
                    ->orWhereHas('treatment.doctors', fn (Builder $doctors) => $doctors->whereKey($doctorId))));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true)
            && ($this->latest_date === null || $this->latest_date->gte(today()));
    }

    /** Desde qué día se le puede ofrecer un horario: hoy o el que pidió, el que sea después. */
    public function firstDate(): CarbonInterface
    {
        return $this->earliest_date?->isAfter(today()) ? $this->earliest_date : today();
    }

    /** ¿Le sirve una cita a esa hora? Cae en su plazo y en la parte del día que pidió. */
    public function accepts(CarbonInterface $startsAt): bool
    {
        return $startsAt->gte($this->firstDate()->startOfDay())
            && ($this->latest_date === null || $startsAt->lte($this->latest_date->endOfDay()))
            && $this->preferred_period->includes($startsAt);
    }
}
