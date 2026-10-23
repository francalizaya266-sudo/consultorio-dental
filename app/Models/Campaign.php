<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\DiscountType;
use App\Enums\TreatmentPlanStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Campaña o promoción; se publica y se retira sola según sus fechas. */
#[Fillable(['name', 'slug', 'summary', 'description', 'image_path', 'treatment_id', 'treatment_package_id', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'promo_code', 'max_redemptions', 'show_on_home', 'whatsapp_text', 'views_count', 'is_active'])]
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

    /** El paquete que ofrece la campaña, si ofrece uno. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TreatmentPackage::class, 'treatment_package_id')->withTrashed();
    }

    /** Los planes que se armaron con el paquete de la campaña. */
    public function plans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class);
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

    /** Descuento en soles sobre un precio, si la promoción es de ese tratamiento (o de cualquiera). */
    public function discountOn(float $price, ?int $treatmentId): float
    {
        if ($price <= 0 || (float) $this->discount_value <= 0 || ($this->treatment_id !== null && $this->treatment_id !== $treatmentId)) {
            return 0.0;
        }

        return match ($this->discount_type) {
            DiscountType::Percent => round($price * min(100, (float) $this->discount_value) / 100, 2),
            DiscountType::Amount => min($price, (float) $this->discount_value),
            default => 0.0,
        };
    }

    /** El precio "desde" del tratamiento en la web con el descuento de la campaña, o null si la campaña no lo baja. */
    public function priceFor(Treatment $treatment): ?float
    {
        if ($treatment->webPrice() === null) {
            return null;
        }

        $discount = $this->discountOn((float) $treatment->price_from, $treatment->getKey());

        return $discount > 0 ? round((float) $treatment->price_from - $discount, 2) : null;
    }

    public function hasSlotsLeft(): bool
    {
        return $this->max_redemptions === null || $this->usedSlots() < $this->max_redemptions;
    }

    /**
     * Cupos usados. Si ofrece un paquete, cuentan las personas que lo aceptaron (en un 2x1, las dos);
     * si no, las citas reservadas.
     */
    public function usedSlots(): int
    {
        if ($this->treatment_package_id !== null) {
            return $this->plans()->whereNotNull('accepted_at')->whereNot('status', TreatmentPlanStatus::Cancelled)->count();
        }

        return $this->appointments()->whereNot('status', AppointmentStatus::Cancelled)->count();
    }
}
