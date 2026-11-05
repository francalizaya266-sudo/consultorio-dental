<?php

namespace App\Models;

use App\Enums\SupplyCategory;
use App\Models\Concerns\Auditable;
use App\Support\Quantity;
use Database\Factories\SupplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Un material del consultorio. Lo que hay ("stock") lo mueve solo el InventoryService: entra con
 * cada compra, sale con cada atención y se corrige con el conteo del mes.
 */
#[Fillable(['name', 'category', 'unit', 'min_stock', 'unit_cost', 'supplier_id', 'tracks_serial', 'notes', 'is_active'])]
class Supply extends Model
{
    /** @use HasFactory<SupplyFactory> */
    use Auditable, HasFactory;

    /** Días antes del vencimiento en que un lote empieza a avisar. */
    public const EXPIRY_WARNING_DAYS = 30;

    protected $attributes = [
        'unit' => 'unidad',
        'stock' => 0,
        'min_stock' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'category' => SupplyCategory::class,
            'stock' => 'decimal:2',
            'min_stock' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'tracks_serial' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(SupplyBatch::class);
    }

    /** Los lotes de los que todavía queda algo. */
    public function availableBatches(): HasMany
    {
        return $this->batches()->where('remaining', '>', 0);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function treatments(): BelongsToMany
    {
        return $this->belongsToMany(Treatment::class, 'treatment_supplies')->withPivot('quantity')->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Hay menos de lo mínimo. Un material que todavía no se cargó ni se contó no avisa:
     * recién empieza a usarse el inventario.
     */
    #[Scope]
    protected function belowMinimum(Builder $query): Builder
    {
        return $query->active()
            ->where('min_stock', '>', 0)
            ->whereColumn('stock', '<', 'min_stock')
            ->whereHas('movements');
    }

    /** Bajo el mínimo y sin pedir todavía: lo que hay que pedir hoy. */
    #[Scope]
    protected function toReorder(Builder $query): Builder
    {
        return $query->belowMinimum()->whereDoesntHave('orderItems', fn (Builder $items) => $items
            ->whereColumn('quantity', '>', DB::raw('coalesce(received_quantity, 0)'))
            ->whereHas('purchaseOrder', fn (Builder $order) => $order->open()));
    }

    /** Con algún lote que vence en los próximos 30 días (o ya venció) y del que queda algo. */
    #[Scope]
    protected function expiringSoon(Builder $query): Builder
    {
        return $query->active()->whereHas('batches', fn (Builder $batches) => $batches->expiringSoon());
    }

    public function isLow(): bool
    {
        return (float) $this->min_stock > 0 && (float) $this->stock < (float) $this->min_stock;
    }

    /** "12 carpules". */
    public function quantity(float|int|string|null $quantity): string
    {
        return Quantity::format($quantity, $this->unit);
    }

    /** Lo que se sugiere pedir: hasta el doble del mínimo, en unidades enteras. */
    /** La unidad para poner al lado de una cantidad que se está escribiendo: "carpule" o "carpules". */
    public static function unitFor(mixed $supplyId, mixed $quantity): ?string
    {
        $unit = filled($supplyId) ? static::query()->whereKey($supplyId)->value('unit') : null;

        return $unit === null ? null : Quantity::unit($unit, is_numeric($quantity) ? (float) $quantity : 2.0);
    }

    public function suggestedOrder(): float
    {
        return max(1, ceil((float) $this->min_stock * 2 - max(0, (float) $this->stock)));
    }
}
