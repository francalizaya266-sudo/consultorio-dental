<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada entrada (+) o salida (−) de un material, con su motivo y quién la hizo. Es la historia
 * del inventario: no se edita ni se borra; un error se corrige con otro movimiento.
 */
#[Fillable(['supply_id', 'supply_batch_id', 'type', 'quantity', 'unit_cost', 'reason', 'patient_id', 'consultation_id', 'appointment_id', 'purchase_order_id', 'user_id', 'reverted_at'])]
class StockMovement extends Model
{
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'reverted_at' => 'datetime',
        ];
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplyBatch::class, 'supply_batch_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lo usado en atenciones que sigue en pie (no se devolvió al reabrir). */
    #[Scope]
    protected function used(Builder $query): Builder
    {
        return $query->where('type', StockMovementType::Use)->whereNull('reverted_at');
    }
}
