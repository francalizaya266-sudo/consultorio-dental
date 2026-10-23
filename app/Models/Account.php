<?php

namespace App\Models;

use App\Enums\PatientRelationship;
use App\Models\Concerns\Auditable;
use App\Support\Phone;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cuenta de paciente: un número de celular con uno o varios pacientes a cargo. */
#[Fillable(['phone', 'phone_verified_at', 'accepts_promotions', 'promotions_consent_at', 'privacy_accepted_at', 'privacy_version', 'blocked_at', 'blocked_reason', 'blocked_by_user_id', 'web_booking_limit', 'last_seen_at'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, SoftDeletes, Auditable;

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'accepts_promotions' => 'boolean',
            'promotions_consent_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'blocked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'web_booking_limit' => 'integer',
        ];
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function rememberedDevices(): HasMany
    {
        return $this->hasMany(RememberedDevice::class);
    }

    public function magicLinks(): HasMany
    {
        return $this->hasMany(MagicLink::class);
    }

    public function appointments(): HasManyThrough
    {
        return $this->hasManyThrough(Appointment::class, Patient::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by_user_id');
    }

    /** El paciente titular del celular ("para mí"). */
    public function holder(): HasOne
    {
        return $this->hasOne(Patient::class)->where('relationship', PatientRelationship::Self);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /** Tope de citas web futuras para todo el celular. */
    public function webBookingLimit(): int
    {
        return $this->web_booking_limit ?? (int) Setting::get('booking.max_web_appointments_per_phone', 6);
    }

    public static function findByPhone(string $phone): ?self
    {
        return static::where('phone', Phone::normalize($phone))->first();
    }
}
