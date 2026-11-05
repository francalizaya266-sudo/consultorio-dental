<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un pedido al proveedor: sale por WhatsApp y, al llegar, cada material entra con su lote. */
#[Fillable(['supplier_id', 'status', 'notes', 'sent_at', 'received_at', 'created_by_user_id', 'received_by_user_id'])]
class PurchaseOrder extends Model
{
    use Auditable;

    protected $attributes = ['status' => 'sent'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /** Pedidos que todavía esperan que llegue algo. */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereIn('status', [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial]);
    }

    /** "Guantes de nitrilo M (2 cajas), Lidocaína 2 % (50 carpules) y 1 más". */
    public function summary(int $limit = 2): string
    {
        $items = $this->items->map(fn (PurchaseOrderItem $item) => $item->supply->name.' ('.$item->supply->quantity($item->quantity).')');

        return $items->take($limit)->implode(', ').($items->count() > $limit ? ' y '.($items->count() - $limit).' más' : '');
    }
}
