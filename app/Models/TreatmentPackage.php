<?php

namespace App\Models;

use App\Enums\PlanPaymentMode;
use App\Models\Concerns\Auditable;
use Database\Factories\TreatmentPackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Paquete que arma el doctor: varios tratamientos a un precio y cómo se puede pagar
 * (todo junto, con un descuento, o una inicial y cuotas mensuales).
 */
#[Fillable(['name', 'description', 'price', 'for_two', 'allows_upfront', 'upfront_discount', 'allows_installments', 'down_payment', 'installments', 'monthly_amount', 'is_active', 'created_by_user_id'])]
class TreatmentPackage extends Model
{
    /** @use HasFactory<TreatmentPackageFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'for_two' => 'boolean',
            'allows_upfront' => 'boolean',
            'upfront_discount' => 'decimal:2',
            'allows_installments' => 'boolean',
            'down_payment' => 'decimal:2',
            'installments' => 'integer',
            'monthly_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentPackageItem::class)->orderBy('sort_order')->chaperone('package');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Lo que costaría todo por separado, con los precios del catálogo de hoy. */
    public function normalPrice(): float
    {
        $this->loadMissing('items.treatment');

        return round($this->items->sum(fn (TreatmentPackageItem $item) => $item->normalPrice()), 2);
    }

    public function savings(): float
    {
        return max(0, round($this->normalPrice() - (float) $this->price, 2));
    }

    /** Lo que incluye en una línea: "Colocación de brackets · Control de ortodoncia × 18". */
    public function contents(): string
    {
        $this->loadMissing('items.treatment');

        return $this->items->map(fn (TreatmentPackageItem $item) => $item->label())->implode(' · ');
    }

    /** La forma de pago con que llega el plan a la firma: en cuotas si el paquete las ofrece. */
    public function defaultPaymentMode(): PlanPaymentMode
    {
        return match (true) {
            $this->allows_installments => PlanPaymentMode::Installments,
            $this->allows_upfront => PlanPaymentMode::Upfront,
            default => PlanPaymentMode::PerSession,
        };
    }

    /**
     * Condiciones del paquete para armar el cronograma.
     *
     * @return array{down_payment: float, installments: ?int, monthly_amount: ?float, upfront_discount: float}
     */
    public function terms(): array
    {
        return [
            'down_payment' => (float) $this->down_payment,
            'installments' => $this->installments,
            'monthly_amount' => $this->monthly_amount !== null ? (float) $this->monthly_amount : null,
            'upfront_discount' => $this->allows_upfront ? (float) $this->upfront_discount : 0.0,
        ];
    }
}
