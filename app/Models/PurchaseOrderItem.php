<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un material del pedido: cuánto se pidió y cuánto llegó. */
#[Fillable(['purchase_order_id', 'supply_id', 'quantity', 'received_quantity'])]
class PurchaseOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'received_quantity' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    /** Lo que falta que llegue. */
    public function pending(): float
    {
        return max(0, (float) $this->quantity - (float) $this->received_quantity);
    }
}
