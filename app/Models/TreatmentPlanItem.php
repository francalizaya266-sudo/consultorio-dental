<?php

namespace App\Models;

use App\Enums\PlanItemStatus;
use App\Exceptions\ClinicalException;
use App\Models\Concerns\Auditable;
use App\Support\Teeth;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Procedimiento del plan, con su costo y sesiones. Una vez aceptado, su precio ya no cambia. */
#[Fillable(['treatment_plan_id', 'treatment_id', 'treatment_package_id', 'description', 'tooth', 'surfaces', 'quantity', 'unit_price', 'discount', 'sessions_planned', 'sessions_done', 'session_interval_days', 'status', 'accepted_at', 'in_payment_schedule', 'sort_order'])]
class TreatmentPlanItem extends Model
{
    use Auditable;

    /** Lo que el paciente firmó no se cambia ni se quita: se cancela y se agrega el correcto. */
    public const AGREED_FIELDS = ['treatment_id', 'description', 'quantity', 'unit_price', 'discount'];

    /** Por hacer: aún no se termina ni se canceló. */
    public const OPEN_STATUSES = [PlanItemStatus::Pending, PlanItemStatus::InProgress];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'status' => PlanItemStatus::class,
            'accepted_at' => 'datetime',
            'in_payment_schedule' => 'boolean',
            'session_interval_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TreatmentPlanItem $item) {
            if ($item->getOriginal('accepted_at') !== null && $item->isDirty(self::AGREED_FIELDS)) {
                throw ClinicalException::agreedPrice();
            }
        });

        static::deleting(function (TreatmentPlanItem $item) {
            if ($item->getOriginal('accepted_at') !== null) {
                throw ClinicalException::agreedPrice();
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    /** El paquete de donde vino, si vino de uno. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TreatmentPackage::class, 'treatment_package_id')->withTrashed();
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Atenciones en las que se trabajó este procedimiento. */
    public function consultations(): BelongsToMany
    {
        return $this->belongsToMany(Consultation::class, 'consultation_plan_items')->withTimestamps();
    }

    public function subtotal(): float
    {
        return round($this->quantity * (float) $this->unit_price - (float) $this->discount, 2);
    }

    /** Cada cuánto puede ir a control, en días: según sus brackets, cada 15 días, cada mes o más espaciado. */
    public const FREQUENCIES = [15, 21, 30, 45, 60];

    /**
     * Las frecuencias para elegir ("Cada 15 días", "Cada mes"…), con la que ya tiene si no es una de ellas.
     *
     * @return array<int, string>
     */
    public static function frequencyOptions(?int $current = null): array
    {
        return collect(self::FREQUENCIES)
            ->push($current)
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (int $days) => [$days => ucfirst(self::everyText($days))])
            ->all();
    }

    /** "cada 15 días", "cada mes", "cada 3 semanas": cada cuánto va a sus sesiones. */
    public static function everyText(int $days): string
    {
        return match (true) {
            $days % 30 === 0 => $days === 30 ? 'cada mes' : 'cada '.intdiv($days, 30).' meses',
            $days % 7 === 0 => $days === 7 ? 'cada semana' : 'cada '.intdiv($days, 7).' semanas',
            default => "cada {$days} días",
        };
    }

    /** "Curación con resina · pieza 36 (MO)". */
    public function label(bool $withTooth = true): string
    {
        return $this->description.($withTooth && filled($this->tooth) ? ' · pieza '.$this->tooth.(filled($this->surfaces) ? " ({$this->surfaces})" : '') : '');
    }

    /** "Primer molar inferior izquierdo · caras oclusal y mesial", para el paciente. Null sin pieza reconocible. */
    public function toothDescription(): ?string
    {
        $name = Teeth::nameOf($this->tooth);

        return $name ? collect([$name, Teeth::surfacesText($this->surfaces)])->filter()->implode(' · ') : null;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
