<?php

namespace App\Services\Inventory;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InventoryException;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyBatch;
use App\Models\Treatment;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\StaffAlerts;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El inventario casi solo: cada compra entra con su lote, cada atención descuenta lo que usó
 * (primero lo que vence antes) y el conteo del mes corrige las diferencias. Todo queda como
 * movimiento, con quién y por qué. Si un material baja del mínimo, recepción recibe el aviso.
 *
 * Lo que hay ("stock") es la suma de los movimientos. Si se usa algo que no estaba cargado, igual
 * se registra (se usó): queda en negativo hasta que llegue la compra o se cuente.
 */
final class InventoryService
{
    public function __construct(private readonly StaffAlerts $alerts) {}

    /**
     * Entra material: una compra que llegó o lo que ya había al empezar a usar el inventario.
     *
     * @param  array{lot?: string|null, serial?: string|null, expires_on?: mixed, unit_cost?: float|int|string|null}  $batch
     */
    public function receive(Supply $supply, float $quantity, User $by, array $batch = [], ?PurchaseOrder $order = null): SupplyBatch
    {
        $quantity = round($quantity, 2);

        if ($quantity <= 0) {
            throw InventoryException::invalidQuantity();
        }

        $serial = filled($batch['serial'] ?? null) ? trim((string) $batch['serial']) : null;

        if ($serial !== null && $supply->batches()->where('serial', $serial)->exists()) {
            throw InventoryException::duplicateSerial($serial);
        }

        $unitCost = filled($batch['unit_cost'] ?? null) ? round((float) $batch['unit_cost'], 2) : null;

        return DB::transaction(function () use ($supply, $quantity, $by, $batch, $order, $serial, $unitCost) {
            // Lo que se usó sin estar cargado se descuenta de lo que llega: así los lotes dicen lo que de verdad queda.
            $unloaded = max(0, round((float) $supply->availableBatches()->sum('remaining') - (float) $supply->stock, 2));

            $created = $supply->batches()->create([
                'lot' => filled($batch['lot'] ?? null) ? trim((string) $batch['lot']) : null,
                'serial' => $serial,
                'expires_on' => filled($batch['expires_on'] ?? null) ? $batch['expires_on'] : null,
                'quantity' => $quantity,
                'remaining' => round($quantity - min($quantity, $unloaded), 2),
                'unit_cost' => $unitCost,
                'received_at' => now(),
                'purchase_order_id' => $order?->getKey(),
            ]);

            $this->record($supply, StockMovementType::Receipt, $quantity, $by, [
                'supply_batch_id' => $created->getKey(),
                'purchase_order_id' => $order?->getKey(),
                'unit_cost' => $unitCost ?? $supply->unit_cost,
            ]);

            if ($unitCost !== null) {
                $supply->update(['unit_cost' => $unitCost]);
            }

            return $created;
        });
    }

    /**
     * Implantes: cada número de serie es una unidad, para saber después en qué paciente quedó.
     *
     * @param  list<string>  $serials
     * @param  array{lot?: string|null, expires_on?: mixed, unit_cost?: float|int|string|null}  $batch
     * @return Collection<int, SupplyBatch>
     */
    public function receiveSerials(Supply $supply, array $serials, User $by, array $batch = [], ?PurchaseOrder $order = null): Collection
    {
        $serials = self::serials($serials);

        return DB::transaction(fn () => collect($serials)->map(fn (string $serial) => $this->receive($supply, 1, $by, [...$batch, 'serial' => $serial], $order)));
    }

    /**
     * Sale material, primero de lo que vence antes (y de un lote vencido solo si no queda otro).
     *
     * @param  array{patient_id?: int|null, consultation_id?: int|null, appointment_id?: int|null, reason?: string|null}  $context
     * @return Collection<int, StockMovement>
     */
    public function take(Supply $supply, float $quantity, StockMovementType $type, User $by, array $context = [], ?SupplyBatch $batch = null): Collection
    {
        $left = round($quantity, 2);

        if ($left <= 0) {
            throw InventoryException::invalidQuantity();
        }

        return DB::transaction(function () use ($supply, $left, $type, $by, $context, $batch) {
            $before = (float) $supply->stock;
            $movements = collect();

            foreach ($batch ? [$batch->fresh()] : $this->firstToExpire($supply) as $source) {
                $part = round(min($left, (float) $source->remaining), 2);

                if ($part <= 0) {
                    continue;
                }

                $source->decrement('remaining', $part);
                $movements->push($this->record($supply, $type, -$part, $by, [...$context, 'supply_batch_id' => $source->getKey(), 'unit_cost' => $source->unit_cost ?? $supply->unit_cost]));
                $left = round($left - $part, 2);

                if ($left <= 0) {
                    break;
                }
            }

            if ($left > 0) {
                $movements->push($this->record($supply, $type, -$left, $by, [...$context, 'unit_cost' => $supply->unit_cost]));
            }

            $this->alertIfRunningLow($supply, $before);

            return $movements;
        });
    }

    /**
     * Al terminar la atención sale lo que se usó: la receta de lo trabajado, corregida por el doctor.
     *
     * @param  list<array{supply_id: int|string|null, quantity: float|int|string|null, batch_id?: int|string|null}>  $lines
     */
    public function useForConsultation(Consultation $consultation, array $lines, User $by): void
    {
        DB::transaction(function () use ($consultation, $lines, $by) {
            // Si ya había algo registrado (no debería), se devuelve antes: nunca se descuenta dos veces.
            $this->revertConsultation($consultation, $by);

            foreach (self::normalize($lines) as $line) {
                $supply = Supply::query()->find($line['supply_id']);

                if ($supply === null) {
                    continue;
                }

                $batch = $line['batch_id'] ? $supply->batches()->find($line['batch_id']) : null;

                $this->take($supply, $line['quantity'], StockMovementType::Use, $by, [
                    'patient_id' => $consultation->patient_id,
                    'consultation_id' => $consultation->getKey(),
                    'appointment_id' => $consultation->appointment_id,
                ], $batch);
            }
        });
    }

    /** Al reabrir la atención, lo usado vuelve al stock (y a su lote) hasta que se vuelva a terminar. */
    public function revertConsultation(Consultation $consultation, User $by): void
    {
        DB::transaction(function () use ($consultation, $by) {
            $uses = StockMovement::query()->used()->where('consultation_id', $consultation->getKey())->with(['supply', 'batch'])->get();
            $now = now();

            foreach ($uses as $use) {
                $use->batch?->increment('remaining', -(float) $use->quantity);
                $this->record($use->supply, StockMovementType::Return, -(float) $use->quantity, $by, [
                    'supply_batch_id' => $use->supply_batch_id,
                    'patient_id' => $use->patient_id,
                    'consultation_id' => $use->consultation_id,
                    'appointment_id' => $use->appointment_id,
                    'unit_cost' => $use->unit_cost,
                    'reason' => 'Se reabrió la atención',
                ]);
                $use->update(['reverted_at' => $now]);
            }
        });
    }

    /**
     * Cita marcada como atendida sin atención escrita (la cerró recepción): sale la receta de su
     * tratamiento. Los implantes no, porque no se sabe qué unidad se usó.
     */
    public function useForAppointment(Appointment $appointment, User $by): void
    {
        if ($appointment->consultation()->exists() || StockMovement::query()->where('appointment_id', $appointment->getKey())->exists()) {
            return;
        }

        $lines = array_filter(
            $this->recipe([$appointment->treatment_id]),
            fn (array $line) => ! Supply::query()->whereKey($line['supply_id'])->value('tracks_serial'),
        );

        DB::transaction(function () use ($appointment, $lines, $by) {
            foreach (self::normalize(array_values($lines)) as $line) {
                $this->take(Supply::query()->findOrFail($line['supply_id']), $line['quantity'], StockMovementType::Use, $by, [
                    'patient_id' => $appointment->patient_id,
                    'appointment_id' => $appointment->getKey(),
                ]);
            }
        });
    }

    /**
     * Lo que se espera usar en la atención: la receta de lo que se hizo hoy (los tratamientos marcados y
     * cada procedimiento del plan trabajado), no de lo programado. Si la atención se reabrió, lo que se
     * registró al terminarla. Los implantes van de a una unidad, para elegir su número de serie.
     *
     * @param  list<int|string>|null  $planItemIds  Lo marcado en pantalla, aunque no se haya guardado.
     * @param  list<int|string>|null  $treatmentIds  Igual, para los tratamientos fuera del plan.
     * @return list<array{supply_id: int, quantity: float, batch_id: int|null}>
     */
    public function plannedUsage(Consultation $consultation, ?array $planItemIds = null, ?array $treatmentIds = null): array
    {
        $lastReverted = StockMovement::query()->where('consultation_id', $consultation->getKey())->where('type', StockMovementType::Use)->max('reverted_at');

        if ($lastReverted !== null) {
            return StockMovement::query()
                ->where('consultation_id', $consultation->getKey())
                ->where('type', StockMovementType::Use)
                ->where('reverted_at', $lastReverted)
                ->with('supply')
                ->get()
                ->groupBy(fn (StockMovement $use) => $use->supply_id.'-'.($use->supply->tracks_serial ? $use->supply_batch_id : ''))
                ->map(fn (Collection $uses) => [
                    'supply_id' => $uses->first()->supply_id,
                    'quantity' => round(-(float) $uses->sum('quantity'), 2),
                    'batch_id' => $uses->first()->supply->tracks_serial ? $uses->first()->supply_batch_id : null,
                ])
                ->values()
                ->all();
        }

        $planItemIds ??= $consultation->planItems()->pluck('treatment_plan_items.id')->all();
        $treatmentIds ??= $consultation->treatments()->pluck('treatments.id')->all();
        $treatments = [
            ...TreatmentPlanItem::query()->whereKey($planItemIds)->pluck('treatment_id')->filter()->all(),
            ...array_map('intval', $treatmentIds),
        ];

        $serialized = Supply::query()->where('tracks_serial', true)->pluck('id')->all();

        return collect($this->recipe($treatments))
            ->flatMap(fn (array $line) => in_array($line['supply_id'], $serialized, true)
                ? array_fill(0, (int) max(1, ceil($line['quantity'])), [...$line, 'quantity' => 1.0])
                : [$line])
            ->values()
            ->all();
    }

    /** @return list<string> Lo que salió en la atención ("Lidocaína 2%: 2 carpules"); cada implante con su serie. */
    public function usedIn(Consultation $consultation): array
    {
        return StockMovement::query()
            ->used()
            ->where('consultation_id', $consultation->getKey())
            ->with(['supply', 'batch'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (StockMovement $use) => $use->supply_id.'-'.($use->supply->tracks_serial ? $use->getKey() : ''))
            ->map(function (Collection $uses) {
                $use = $uses->first();

                return $use->supply->name.': '.$use->supply->quantity(-(float) $uses->sum('quantity'))
                    .($use->supply->tracks_serial ? ' · '.($use->batch?->label() ?? 'serie sin registrar') : '');
            })
            ->values()
            ->all();
    }

    /**
     * Los implantes que tiene el paciente, con su serie y lote: si el fabricante retira un lote,
     * se sabe a quién llamar.
     *
     * @return Collection<int, StockMovement>
     */
    public function implantsOf(Patient $patient): Collection
    {
        return StockMovement::query()
            ->used()
            ->where('patient_id', $patient->getKey())
            ->whereHas('supply', fn ($supply) => $supply->where('tracks_serial', true))
            ->with(['supply', 'batch', 'consultation.doctor', 'appointment.doctor'])
            ->latest('id')
            ->get();
    }

    /**
     * La receta de varios tratamientos sumada por material (un tratamiento repetido cuenta dos veces).
     *
     * @param  list<int|null>  $treatmentIds
     * @return list<array{supply_id: int, quantity: float, batch_id: null}>
     */
    public function recipe(array $treatmentIds): array
    {
        $times = array_count_values(array_map('intval', array_filter($treatmentIds)));

        if ($times === []) {
            return [];
        }

        return DB::table('treatment_supplies')
            ->join('supplies', 'supplies.id', '=', 'treatment_supplies.supply_id')
            ->whereIn('treatment_supplies.treatment_id', array_keys($times))
            ->where('supplies.is_active', true)
            ->orderBy('supplies.category')
            ->orderBy('supplies.name')
            ->get(['treatment_supplies.treatment_id', 'treatment_supplies.supply_id', 'treatment_supplies.quantity'])
            ->groupBy('supply_id')
            ->map(fn (Collection $rows, int $supplyId) => [
                'supply_id' => $supplyId,
                'quantity' => round($rows->sum(fn (object $row) => (float) $row->quantity * $times[$row->treatment_id]), 2),
                'batch_id' => null,
            ])
            ->values()
            ->all();
    }

    /** Lo que cuestan los materiales de una atención de este tratamiento, con el último costo de cada uno. */
    public function recipeCost(Treatment $treatment): float
    {
        return round($treatment->loadMissing('supplies')->supplies->sum(fn (Supply $supply) => (float) $supply->pivot->quantity * (float) $supply->unit_cost), 2);
    }

    /**
     * Conteo: lo contado pasa a ser lo que hay. La diferencia queda como ajuste con quién y por qué,
     * y los lotes se acomodan para sumar lo mismo (lo que falta sale de lo que vence antes).
     */
    public function adjust(Supply $supply, float $counted, string $reason, User $by): void
    {
        $counted = max(0, round($counted, 2));
        $stockDelta = round($counted - (float) $supply->stock, 2);

        if ($stockDelta == 0.0) {
            return;
        }

        DB::transaction(function () use ($supply, $counted, $stockDelta, $reason, $by) {
            $before = (float) $supply->stock;
            $inBatches = round((float) $supply->availableBatches()->sum('remaining'), 2);
            $batchDelta = round($counted - $inBatches, 2);
            $context = ['reason' => $reason];

            if ($batchDelta < 0) {
                $left = -$batchDelta;

                foreach ($this->firstToExpire($supply) as $source) {
                    $part = round(min($left, (float) $source->remaining), 2);
                    $source->decrement('remaining', $part);
                    $this->record($supply, StockMovementType::Adjustment, -$part, $by, [...$context, 'supply_batch_id' => $source->getKey(), 'unit_cost' => $source->unit_cost ?? $supply->unit_cost]);
                    $left = round($left - $part, 2);

                    if ($left <= 0) {
                        break;
                    }
                }
            } elseif ($batchDelta > 0) {
                // Apareció más: va al lote más nuevo que no venció, o a uno nuevo sin lote.
                $target = $supply->batches()->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))->whereNull('serial')->latest('received_at')->latest('id')->first();

                if ($target) {
                    $target->increment('remaining', $batchDelta);
                } else {
                    $target = $supply->batches()->create(['quantity' => $batchDelta, 'remaining' => $batchDelta, 'received_at' => now(), 'unit_cost' => $supply->unit_cost]);
                }

                $this->record($supply, StockMovementType::Adjustment, $batchDelta, $by, [...$context, 'supply_batch_id' => $target->getKey(), 'unit_cost' => $target->unit_cost ?? $supply->unit_cost]);
            }

            // Lo que se había usado sin estar cargado: se corrige sin tocar lotes.
            $unbatched = round($stockDelta - $batchDelta, 2);

            if ($unbatched != 0.0) {
                $this->record($supply, StockMovementType::Adjustment, $unbatched, $by, [...$context, 'unit_cost' => $supply->unit_cost]);
            }

            $this->alertIfRunningLow($supply, $before);
        });
    }

    /** Un lote que venció, se dañó o se perdió sale del stock con su motivo. */
    public function writeOff(SupplyBatch $batch, float $quantity, string $reason, User $by): void
    {
        $batch->loadMissing('supply');

        $this->take($batch->supply, min($quantity, (float) $batch->remaining), StockMovementType::WriteOff, $by, ['reason' => $reason], $batch);
    }

    /** @return Collection<int, Supply> Lo que está bajo el mínimo y no se pidió todavía, de un proveedor o de todos. */
    public function toReorder(?Supplier $supplier = null): Collection
    {
        return Supply::query()
            ->toReorder()
            ->when($supplier, fn ($query) => $query->whereBelongsTo($supplier))
            ->with('supplier')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Arma el pedido. El mensaje se abre en WhatsApp para enviarlo desde el celular del consultorio.
     *
     * @param  list<array{supply_id: int|string|null, quantity: float|int|string|null}>  $items
     */
    public function placeOrder(Supplier $supplier, array $items, User $by, ?string $notes = null): PurchaseOrder
    {
        $items = array_values(array_filter($items, fn (array $item) => filled($item['supply_id'] ?? null) && (float) ($item['quantity'] ?? 0) > 0));

        if ($items === []) {
            throw InventoryException::invalidQuantity();
        }

        return DB::transaction(function () use ($supplier, $items, $by, $notes) {
            $order = PurchaseOrder::create([
                'supplier_id' => $supplier->getKey(),
                'status' => PurchaseOrderStatus::Sent,
                'notes' => filled($notes) ? trim($notes) : null,
                'sent_at' => now(),
                'created_by_user_id' => $by->getKey(),
            ]);

            foreach ($items as $item) {
                $order->items()->create(['supply_id' => $item['supply_id'], 'quantity' => round((float) $item['quantity'], 2)]);
            }

            return $order->load(['supplier', 'items.supply']);
        });
    }

    public function orderMessage(PurchaseOrder $order): string
    {
        $order->loadMissing(['supplier', 'items.supply']);
        $greeting = $order->supplier->contact_name ?: $order->supplier->name;

        return collect([
            "Hola {$greeting}, le escribimos de ".Setting::get('clinic.name').'. Quisiéramos pedir:',
            ...$order->items->map(fn (PurchaseOrderItem $item) => '• '.$item->supply->name.': '.$item->supply->quantity($item->quantity)),
            $order->notes ? "\n".$order->notes : null,
            "\n¿Nos confirma el precio y cuándo llegaría? Gracias.",
        ])->filter()->implode("\n");
    }

    /** El enlace que abre el WhatsApp del proveedor con el pedido escrito, o null si no tiene celular. */
    public function whatsAppUrl(PurchaseOrder $order, ?string $text = null): ?string
    {
        $order->loadMissing('supplier');

        return $order->supplier->hasWhatsApp()
            ? 'https://wa.me/'.Phone::forWhatsApp($order->supplier->phone).'?text='.rawurlencode($text ?? $this->orderMessage($order))
            : null;
    }

    /**
     * Llegó el pedido, o una parte: cada material entra con su lote y vencimiento.
     *
     * @param  array<int|string, array{received?: float|int|string|null, lot?: string|null, expires_on?: mixed, serials?: list<string>|string|null, unit_cost?: float|int|string|null}>  $lines  Por id del ítem del pedido.
     */
    public function receiveOrder(PurchaseOrder $order, array $lines, User $by): void
    {
        if (! $order->status->isOpen()) {
            throw InventoryException::orderClosed();
        }

        DB::transaction(function () use ($order, $lines, $by) {
            $order->loadMissing('items.supply');
            $receivedSomething = false;

            foreach ($order->items as $item) {
                $line = $lines[$item->getKey()] ?? null;

                if ($line === null) {
                    continue;
                }

                $batch = ['lot' => $line['lot'] ?? null, 'expires_on' => $line['expires_on'] ?? null, 'unit_cost' => $line['unit_cost'] ?? null];

                if ($item->supply->tracks_serial) {
                    $quantity = $this->receiveSerials($item->supply, self::serials($line['serials'] ?? []), $by, $batch, $order)->count();
                } else {
                    $quantity = round((float) ($line['received'] ?? 0), 2);

                    if ($quantity > 0) {
                        $this->receive($item->supply, $quantity, $by, $batch, $order);
                    }
                }

                if ($quantity > 0) {
                    $item->update(['received_quantity' => round((float) $item->received_quantity + $quantity, 2)]);
                    $receivedSomething = true;
                }
            }

            if (! $receivedSomething) {
                return;
            }

            $complete = $order->items->every(fn (PurchaseOrderItem $item) => $item->pending() <= 0);

            $order->update([
                'status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::Partial,
                'received_at' => now(),
                'received_by_user_id' => $by->getKey(),
            ]);
        });
    }

    public function cancelOrder(PurchaseOrder $order): void
    {
        if (! $order->status->isOpen()) {
            throw InventoryException::orderClosed();
        }

        $order->update(['status' => PurchaseOrderStatus::Cancelled]);
    }

    /**
     * Las unidades de un implante que todavía están en el consultorio, para elegir cuál se colocó.
     *
     * @return array<int, string> Id del lote => "Serie 123 · lote A · vence 03/2027"
     */
    public function availableUnits(Supply $supply): array
    {
        return $supply->availableBatches()
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get()
            ->mapWithKeys(fn (SupplyBatch $batch) => [$batch->getKey() => collect([
                $batch->serial ? 'Serie '.$batch->serial : null,
                $batch->lot ? 'lote '.$batch->lot : null,
                $batch->expires_on ? 'vence '.$batch->expires_on->format('m/Y') : null,
            ])->filter()->implode(' · ') ?: 'Sin serie'])
            ->all();
    }

    /** @return Collection<int, SupplyBatch> Lo que todavía queda, primero lo que vence antes; lo vencido al final. */
    private function firstToExpire(Supply $supply): Collection
    {
        return $supply->availableBatches()
            ->get()
            ->sort(fn (SupplyBatch $a, SupplyBatch $b) => [$a->isExpired(), $a->expires_on?->getTimestamp() ?? PHP_INT_MAX, $a->received_at->getTimestamp(), $a->getKey()]
                <=> [$b->isExpired(), $b->expires_on?->getTimestamp() ?? PHP_INT_MAX, $b->received_at->getTimestamp(), $b->getKey()])
            ->values();
    }

    /**
     * Cada cambio pasa por aquí: el movimiento queda registrado y lo que hay se actualiza en la
     * misma transacción (sin pasar por la auditoría del material, que ya es este movimiento).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(Supply $supply, StockMovementType $type, float $quantity, ?User $by, array $attributes = []): StockMovement
    {
        $movement = $supply->movements()->create([
            ...$attributes,
            'type' => $type,
            'quantity' => round($quantity, 2),
            'user_id' => $by?->getKey(),
        ]);

        Supply::query()->whereKey($supply->getKey())->increment('stock', round($quantity, 2));
        $supply->forceFill(['stock' => Supply::query()->whereKey($supply->getKey())->value('stock')])->syncOriginalAttribute('stock');

        return $movement;
    }

    /** Si este movimiento lo dejó bajo el mínimo, recepción recibe el aviso en la campana. */
    private function alertIfRunningLow(Supply $supply, float $before): void
    {
        $minimum = (float) $supply->min_stock;

        if ($minimum <= 0 || ! $supply->is_active || $before < $minimum || (float) $supply->stock >= $minimum) {
            return;
        }

        $this->alerts->toReception(
            'Queda poco: '.$supply->name,
            'Hay '.$supply->quantity($supply->stock).' y el mínimo es '.$supply->quantity($supply->min_stock).'. Pídelo por WhatsApp desde Compras.',
            link: ['label' => 'Ir a Compras', 'url' => PurchaseOrderResource::getUrl('index')],
        );
    }

    /**
     * @param  list<array{supply_id: int|string|null, quantity: float|int|string|null, batch_id?: int|string|null}>  $lines
     * @return list<array{supply_id: int, quantity: float, batch_id: int|null}>
     */
    private static function normalize(array $lines): array
    {
        return collect($lines)
            ->filter(fn (array $line) => filled($line['supply_id'] ?? null) && (float) ($line['quantity'] ?? 0) > 0)
            ->map(fn (array $line) => ['supply_id' => (int) $line['supply_id'], 'quantity' => round((float) $line['quantity'], 2), 'batch_id' => filled($line['batch_id'] ?? null) ? (int) $line['batch_id'] : null])
            ->groupBy(fn (array $line) => $line['supply_id'].'-'.$line['batch_id'])
            ->map(fn (Collection $same) => [...$same->first(), 'quantity' => round($same->sum('quantity'), 2)])
            ->values()
            ->all();
    }

    /**
     * Números de serie escritos uno por línea (o separados por comas), sin repetir.
     *
     * @param  list<string>|string|null  $serials
     * @return list<string>
     */
    public static function serials(array|string|null $serials): array
    {
        $list = preg_split('/[\r\n,;]+/', implode("\n", array_map('strval', is_array($serials) ? $serials : [(string) $serials]))) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $list), fn (string $serial) => $serial !== '')));
    }
}
