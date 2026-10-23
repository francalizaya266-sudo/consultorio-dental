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
#[Fillable(['patient_id', 'doctor_id', 'treatment_id', 'treatment_plan_item_id', 'starts_at', 'ends_at', 'buffer_minutes', 'status', 'booked_via', 'origin', 'campaign_id', 'promo_code', 'is_new_patient', 'fixed_date', 'from_verified_device', 'patient_note', 'internal_notes', 'confirmed_at', 'confirmed_via', 'unconfirmed_flagged_at', 'checked_in_at', 'started_at', 'finished_at', 'no_show_at', 'cancelled_at', 'cancelled_by', 'cancelled_by_user_id', 'cancellation_reason', 'reschedule_count', 'created_by_user_id'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use Auditable, HasFactory, SoftDeletes;

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

    /** "Sin confirmar": falta poco y el paciente aún no confirmó con su enlace. */
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
