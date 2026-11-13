<?php

namespace App\Models;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancelledBy;
use App\Enums\ConfirmationChannel;
use App\Models\Concerns\Auditable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cita en la agenda. Ocupa desde starts_at hasta ends_at + buffer_minutes. */
#[Fillable(['patient_id', 'doctor_id', 'treatment_id', 'treatment_plan_item_id', 'series_id', 'starts_at', 'ends_at', 'buffer_minutes', 'custom_duration', 'status', 'booked_via', 'origin', 'campaign_id', 'promo_code', 'is_new_patient', 'fixed_date', 'is_overbooked', 'from_verified_device', 'patient_note', 'internal_notes', 'confirmed_at', 'confirmed_via', 'unconfirmed_flagged_at', 'checked_in_at', 'started_at', 'finished_at', 'no_show_at', 'cancelled_at', 'cancelled_by', 'cancelled_by_user_id', 'cancellation_reason', 'reschedule_count', 'created_by_user_id'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** Horas después del fin de su cita en que alguien "en sala" deja de contarse como esperando. */
    public const WAIT_EXPIRES_HOURS = 3;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'booked_via' => BookingChannel::class,
            'origin' => AppointmentOrigin::class,
            'confirmed_via' => ConfirmationChannel::class,
            'cancelled_by' => CancelledBy::class,
            'is_new_patient' => 'boolean',
            'fixed_date' => 'boolean',
            'custom_duration' => 'boolean',
            'is_overbooked' => 'boolean',
            'from_verified_device' => 'boolean',
            'confirmed_at' => 'datetime',
            'unconfirmed_flagged_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'no_show_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlanItem::class, 'treatment_plan_item_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class);
    }

    public function rescheduleRequests(): HasMany
    {
        return $this->hasMany(RescheduleRequest::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class);
    }

    /** Hasta cuándo queda ocupado el sillón (incluye esterilización). */
    protected function occupiedUntil(): Attribute
    {
        return Attribute::get(fn () => $this->ends_at->copy()->addMinutes($this->buffer_minutes));
    }

    #[Scope]
    protected function occupying(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::occupying());
    }

    #[Scope]
    protected function upcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->whereIn('status', AppointmentStatus::changeable());
    }

    /** De hoy o de mañana: lo que no puede esperar a otro día (por ejemplo, confirmar la asistencia). */
    #[Scope]
    protected function todayOrTomorrow(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', today())->where('starts_at', '<', today()->addDays(2));
    }

    /**
     * "Sin cerrar", con una sola regla para todo el panel: ya pasó su hora y nadie marcó si vino o si faltó, o
     * llegó otro día y su atención nunca se cerró. La de hoy que está en sala o en consulta no cuenta: sigue su curso.
     */
    #[Scope]
    protected function unclosed(Builder $query): Builder
    {
        return $query->where(fn (Builder $open) => $open
            ->where(fn (Builder $unmarked) => $unmarked
                ->whereIn('status', AppointmentStatus::changeable())
                ->where('ends_at', '<', now()))
            ->orWhere(fn (Builder $unfinished) => $unfinished
                ->whereIn('status', [AppointmentStatus::CheckedIn, AppointmentStatus::InProgress])
                ->where('starts_at', '<', today())));
    }

    /** "Por confirmar": citas por venir que el paciente todavía no confirma. */
    #[Scope]
    protected function awaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', AppointmentStatus::Pending)->where('starts_at', '>=', now());
    }

    /**
     * Citas de los últimos dos meses a las que el paciente faltó o que se cancelaron, y después de las cuales no
     * vino ni tiene otra cita: nadie le dio un nuevo horario.
     */
    #[Scope]
    protected function missedAndNotRebooked(Builder $query): Builder
    {
        return $query
            ->whereIn($query->qualifyColumn('status'), AppointmentStatus::missed())
            ->where($query->qualifyColumn('starts_at'), '>=', now()->subDays(Patient::MISSED_VISIT_DAYS))
            ->whereNotExists(fn ($later) => $later
                ->from('appointments as later')
                ->whereColumn('later.patient_id', $query->qualifyColumn('patient_id'))
                ->whereNull('later.deleted_at')
                ->whereNotIn('later.status', AppointmentStatus::missed())
                ->where(fn ($after) => $after
                    ->whereColumn('later.starts_at', '>', $query->qualifyColumn('starts_at'))
                    ->orWhere('later.starts_at', '>=', now())));
    }

    /**
     * En sala de verdad: llegó hoy y su cita no terminó hace horas. Quien sigue "en sala" mucho después es una
     * cita que nadie cerró, no alguien esperando.
     */
    #[Scope]
    protected function waitingNow(Builder $query): Builder
    {
        return $query
            ->whereDate('starts_at', today())
            ->where('status', AppointmentStatus::CheckedIn)
            ->where('ends_at', '>', now()->subHours(self::WAIT_EXPIRES_HOURS));
    }

    /** Citas que quedaron dentro de un bloqueo de horario, de su doctor o de todo el consultorio. */
    #[Scope]
    protected function insideABlock(Builder $query): Builder
    {
        return $query->whereExists(fn ($blocks) => $blocks
            ->from('schedule_blocks')
            ->where(fn ($owner) => $owner
                ->whereNull('schedule_blocks.doctor_id')
                ->orWhereColumn('schedule_blocks.doctor_id', $query->qualifyColumn('doctor_id')))
            ->whereColumn('schedule_blocks.starts_at', '<', $query->qualifyColumn('ends_at'))
            ->whereColumn('schedule_blocks.ends_at', '>', $query->qualifyColumn('starts_at')));
    }

    /** Las citas que le siguen en su serie (las que se agendaron juntas, "cada 4 semanas") y todavía se pueden cancelar. */
    #[Scope]
    protected function laterInSeries(Builder $query, self $appointment): Builder
    {
        return $query
            ->where('series_id', $appointment->series_id ?? '')
            ->where('starts_at', '>', $appointment->starts_at)
            ->whereIn('status', AppointmentStatus::changeable());
    }

    /**
     * Qué lugar ocupa en su serie (las que se agendaron juntas para repetirse), sin contar las canceladas. Null si
     * no es de una serie.
     *
     * @return array{position: int, total: int}|null
     */
    public function seriesPosition(): ?array
    {
        if ($this->series_id === null) {
            return null;
        }

        $series = static::query()
            ->where('series_id', $this->series_id)
            ->where(fn (Builder $kept) => $kept->where('status', '!=', AppointmentStatus::Cancelled)->orWhere('id', $this->getKey()))
            ->orderBy('starts_at')
            ->pluck('id');

        return ['position' => $series->search($this->getKey()) + 1, 'total' => $series->count()];
    }

    /** Cuánto dura la atención, sin el tiempo de esterilización. */
    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at, true);
    }

    /** Ya pasó su hora y nadie anotó que llegó: o vino y se atendió, o no asistió. */
    public function endedWithoutArriving(): bool
    {
        return $this->status->isChangeable() && $this->ends_at->isPast();
    }

    /** Era de un día anterior: lo que se registre ahora (que vino, que se atendió) queda con su fecha y su hora. */
    public function isFromAnEarlierDay(): bool
    {
        return $this->starts_at->lt(today());
    }

    /** De un día pasado y nadie la cerró: lo que se marque ahora queda con la fecha de ese día. */
    public function isLeftOpen(): bool
    {
        return $this->isFromAnEarlierDay() && in_array($this->status, AppointmentStatus::open(), true);
    }

    /** Como en "unclosed": ya pasó su hora sin que nadie marcara si vino, o llegó otro día y no se cerró su atención. */
    public function isUnclosed(): bool
    {
        return $this->endedWithoutArriving() || $this->isLeftOpen();
    }

    /** Minutos que lleva en sala desde que llegó; null si no está esperando. */
    public function waitingMinutes(): ?int
    {
        if ($this->status !== AppointmentStatus::CheckedIn || $this->checked_in_at === null) {
            return null;
        }

        return (int) max(0, $this->checked_in_at->diffInMinutes(now()));
    }

    /** "Por confirmar": falta poco y el paciente aún no confirmó con su enlace. */
    public function needsCall(): bool
    {
        return $this->status === AppointmentStatus::Pending && $this->unconfirmed_flagged_at !== null;
    }

    public function hoursUntilStart(): float
    {
        return now()->diffInMinutes($this->starts_at, false) / 60;
    }

    public function logEvent(string $type, string $actor, array $attributes = []): AppointmentEvent
    {
        return $this->events()->create(['type' => $type, 'actor' => $actor, 'user_id' => auth()->id()] + $attributes);
    }
}
