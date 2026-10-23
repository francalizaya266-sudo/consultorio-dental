<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quién refirió a quién; el beneficio se gana cuando el referido asiste. */
#[Fillable(['referrer_patient_id', 'referred_patient_id', 'appointment_id', 'status', 'qualified_at', 'referrer_redeemed_at', 'referred_redeemed_at'])]
class Referral extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'qualified_at' => 'datetime',
            'referrer_redeemed_at' => 'datetime',
            'referred_redeemed_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'referrer_patient_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'referred_patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
