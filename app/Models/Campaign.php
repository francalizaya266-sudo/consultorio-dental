<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\DiscountType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Campaña o promoción; se publica y se retira sola según sus fechas. */
#[Fillable(['name', 'slug', 'summary', 'description', 'image_path', 'treatment_id', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'promo_code', 'max_redemptions', 'show_on_home', 'whatsapp_text', 'views_count', 'is_active'])]
class Campaign extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'show_on_home' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** Campañas visibles ahora mismo en la web. */
    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    public function hasSlotsLeft(): bool
    {
        return $this->max_redemptions === null
            || $this->appointments()->whereNot('status', AppointmentStatus::Cancelled)->count() < $this->max_redemptions;
    }
}
