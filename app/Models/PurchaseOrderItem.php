<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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

    /** Lo que todavía se espera: de un pedido abierto y sin llegar completo. */
    #[Scope]
    protected function awaited(Builder $query): Builder
    {
        return $query->whereColumn($query->qualifyColumn('quantity'), '>', DB::raw('coalesce('.$query->qualifyColumn('received_quantity').', 0)'))
            ->whereHas('purchaseOrder', fn (Builder $order) => $order->open());
    }

    /** Lo que falta que llegue. */
    public function pending(): float
    {
        return max(0, (float) $this->quantity - (float) $this->received_quantity);
    }
}
