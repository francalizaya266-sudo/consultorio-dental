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
     * Hay menos de lo mínimo, contando solo lo que se puede usar (lo vencido no). Un material que
     * todavía no se cargó ni se contó no avisa: recién empieza a usarse el inventario.
     */
    #[Scope]
    protected function belowMinimum(Builder $query): Builder
    {
        $expired = self::expiredStockQuery();

        return $query->active()
            ->where('min_stock', '>', 0)
            ->whereHas('movements')
            ->whereRaw('supplies.stock - ('.$expired->toSql().') < supplies.min_stock', $expired->getBindings());
    }

    /**
     * Lo que hay que pedir: aun con lo que ya viene en camino no llega al mínimo. Es la misma cuenta
     * en el menú, en la pestaña "Por pedir", en Hoy y en el pedido que se arma.
     */
    #[Scope]
    protected function toReorder(Builder $query): Builder
    {
        $expired = self::expiredStockQuery();
        $onOrder = self::onOrderQuery();

        return $query->active()
            ->where('min_stock', '>', 0)
            ->whereHas('movements')
            ->whereRaw(
                'supplies.stock - ('.$expired->toSql().') + ('.$onOrder->toSql().') < supplies.min_stock',
                [...$expired->getBindings(), ...$onOrder->getBindings()],
            );
    }

    /** Subconsulta: lo vencido de cada material que todavía no se dio de baja. */
    public static function expiredStockQuery(): Builder
    {
        return SupplyBatch::query()
            ->selectRaw('coalesce(sum(remaining), 0)')
            ->whereColumn('supply_batches.supply_id', 'supplies.id')
            ->expired();
    }

    /** Subconsulta: lo que ya se pidió de cada material y falta que llegue. */
    public static function onOrderQuery(): Builder
    {
        return PurchaseOrderItem::query()
            ->selectRaw('coalesce(sum(quantity - coalesce(received_quantity, 0)), 0)')
            ->whereColumn('purchase_order_items.supply_id', 'supplies.id')
            ->awaited();
    }

    /** Con algún lote que vence en los próximos 30 días (o ya venció) y del que queda algo. */
    #[Scope]
    protected function expiringSoon(Builder $query): Builder
    {
        return $query->active()->whereHas('batches', fn (Builder $batches) => $batches->expiringSoon());
    }

    public function isLow(): bool
    {
        return (float) $this->min_stock > 0 && $this->usableStock() < (float) $this->min_stock;
    }

    /** Lo vencido que todavía no se dio de baja: está en el cajón pero no se usa. */
    public function expiredStock(): float
    {
        $expired = array_key_exists('expired_stock', $this->attributes)
            ? $this->attributes['expired_stock']
            : $this->batches()->expired()->sum('remaining');

        return round(min(max(0, (float) $this->stock), (float) $expired), 2);
    }

    /** Lo que hay y se puede usar: sin lo vencido. */
    public function usableStock(): float
    {
        return round((float) $this->stock - $this->expiredStock(), 2);
    }

    /** Lo que ya se pidió y falta que llegue. */
    public function onOrder(): float
    {
        $onOrder = array_key_exists('on_order', $this->attributes)
            ? $this->attributes['on_order']
            : $this->orderItems()->awaited()->sum(DB::raw('quantity - coalesce(received_quantity, 0)'));

        return round((float) $onOrder, 2);
    }

    /** "12 carpules". */
    public function quantity(float|int|string|null $quantity): string
    {
        return Quantity::format($quantity, $this->unit);
    }

    /** La unidad para poner al lado de una cantidad que se está escribiendo: "carpule" o "carpules". */
    public static function unitFor(mixed $supplyId, mixed $quantity): ?string
    {
        $unit = filled($supplyId) ? static::query()->whereKey($supplyId)->value('unit') : null;

        return $unit === null ? null : Quantity::unit($unit, is_numeric($quantity) ? (float) $quantity : 2.0);
    }

    /** Lo que se sugiere pedir: hasta el doble del mínimo, sin contar lo vencido y restando lo que ya viene. */
    public function suggestedOrder(): float
    {
        return max(1, ceil((float) $this->min_stock * 2 - max(0, $this->usableStock()) - $this->onOrder()));
    }
}
