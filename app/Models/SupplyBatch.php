<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un lote que entró (o, en los implantes, una unidad con su número de serie). */
#[Fillable(['supply_id', 'lot', 'serial', 'expires_on', 'quantity', 'remaining', 'unit_cost', 'received_at', 'purchase_order_id'])]
class SupplyBatch extends Model
{
    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'quantity' => 'decimal:2',
            'remaining' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    #[Scope]
    protected function available(Builder $query): Builder
    {
        return $query->where('remaining', '>', 0);
    }

    #[Scope]
    protected function expiringSoon(Builder $query): Builder
    {
        return $query->available()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', today()->addDays(Supply::EXPIRY_WARNING_DAYS));
    }

    /** Ya venció y todavía queda algo: no se usa, se da de baja. */
    #[Scope]
    protected function expired(Builder $query): Builder
    {
        return $query->available()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', today());
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }

    /** "Lote A123 · serie 9981 · vence 03/2027". */
    public function label(): string
    {
        return collect([
            $this->lot ? 'Lote '.$this->lot : null,
            $this->serial ? 'serie '.$this->serial : null,
            $this->expires_on ? 'vence '.$this->expires_on->format('d/m/Y') : null,
        ])->filter()->implode(' · ') ?: 'Sin lote';
    }
}
