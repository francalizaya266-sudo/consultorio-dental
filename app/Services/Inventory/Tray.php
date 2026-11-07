<?php

namespace App\Services\Inventory;

use App\Filament\Support\ClinicalAlerts;
use App\Models\Appointment;
use App\Models\Supply;
use App\Models\SupplyBatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja de un día: para cada cita, lo que se va a usar según la receta del tratamiento, de qué lote
 * sacarlo (el que vence primero), qué implante (una serie distinta para cada cita) y lo que hay que saber
 * del paciente antes de prepararla (alergias como el látex, anticoagulantes).
 */
final class Tray
{
    /**
     * @return list<array{appointment: Appointment, alerts: list<array{text: string, severe: bool}>, items: list<array{name: string, quantity: string, from: string|null, short: string|null}>}>
     */
    public function for(CarbonInterface $day): array
    {
        $appointments = Appointment::query()
            ->occupying()
            ->whereDate('starts_at', $day)
            ->with(['patient.medicalBackground', 'treatment', 'doctor'])
            ->orderBy('starts_at')
            ->get();

        $recipes = DB::table('treatment_supplies')
            ->whereIn('treatment_id', $appointments->pluck('treatment_id')->filter()->unique())
            ->get(['treatment_id', 'supply_id', 'quantity'])
            ->groupBy('treatment_id');

        $supplies = Supply::query()
            ->active()
            ->whereKey($recipes->flatten(1)->pluck('supply_id')->unique())
            ->with(['availableBatches'])
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        /** @var array<int, float> $used Lo que ya se separó para las citas anteriores del día. */
        $used = [];

        return $appointments->map(function (Appointment $appointment) use ($recipes, $supplies, &$used) {
            $items = collect($recipes->get($appointment->treatment_id, []))
                ->filter(fn (object $row) => $supplies->has($row->supply_id))
                ->sortBy(fn (object $row) => [$supplies[$row->supply_id]->category, $supplies[$row->supply_id]->name])
                ->map(function (object $row) use ($supplies, &$used) {
                    /** @var Supply $supply */
                    $supply = $supplies[$row->supply_id];
                    $quantity = (float) $row->quantity;
                    $before = $used[$supply->getKey()] ?? 0.0;
                    $used[$supply->getKey()] = $before + $quantity;
                    $left = $supply->usableStock() - $before;

                    return [
                        'name' => $supply->name,
                        'quantity' => $supply->quantity($quantity),
                        'from' => $this->from($supply, $before),
                        'short' => $left < $quantity ? ($left > 0 ? 'Solo quedan '.$supply->quantity($left) : 'No queda') : null,
                    ];
                })
                ->values()
                ->all();

            return [
                'appointment' => $appointment,
                'alerts' => $appointment->patient ? ClinicalAlerts::withSeverity($appointment->patient) : [],
                'items' => $items,
            ];
        })->all();
    }

    /**
     * De dónde sacarlo: el lote que vence primero. Para un implante, la unidad que le toca a esta cita (las citas
     * anteriores del día ya se llevaron las primeras).
     */
    private function from(Supply $supply, float $alreadyTaken): ?string
    {
        $batches = $supply->availableBatches
            ->reject(fn (SupplyBatch $batch) => $batch->isExpired())
            ->sortBy(fn (SupplyBatch $batch) => [$batch->expires_on?->getTimestamp() ?? PHP_INT_MAX, $batch->received_at?->getTimestamp() ?? 0, $batch->getKey()])
            ->values();

        // Se salta lo que ya se separó: el primer lote del que todavía queda algo para esta cita.
        $skip = $alreadyTaken;
        $batch = $batches->first(function (SupplyBatch $batch) use (&$skip) {
            if ($skip < (float) $batch->remaining) {
                return true;
            }

            $skip -= (float) $batch->remaining;

            return false;
        });

        return $batch && ($batch->lot || $batch->serial || $batch->expires_on) ? $batch->label() : null;
    }
}
