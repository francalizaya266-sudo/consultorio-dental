<?php

namespace App\Services\Inventory;

use App\Models\Appointment;
use App\Models\Supply;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hasta cuándo alcanza cada material: se resta día por día lo que piden las recetas de las citas de las
 * próximas dos semanas. Así se pide antes de bajar del mínimo, no cuando ya faltó. Se calcula una vez por
 * pedido (el servicio vive lo que dura la página).
 */
class SupplyForecast
{
    /** Días hacia adelante que se miran: las citas más lejanas todavía cambian mucho. */
    public const HORIZON_DAYS = 14;

    /** @var Collection<int, array{supply: Supply, runs_out_on: CarbonImmutable|null, below_min_on: CarbonImmutable|null, needed: float}>|null */
    private ?Collection $forecast = null;

    /**
     * Para cada material en uso que las citas van a gastar: el día en que no alcanza para las citas de ese día
     * (se acaba), el día en que baja del mínimo (si hoy todavía no lo está) y cuánto piden las citas en total.
     *
     * @return Collection<int, array{supply: Supply, runs_out_on: CarbonImmutable|null, below_min_on: CarbonImmutable|null, needed: float}>
     */
    public function all(): Collection
    {
        return $this->forecast ??= $this->compute();
    }

    /** @return array{supply: Supply, runs_out_on: CarbonImmutable|null, below_min_on: CarbonImmutable|null, needed: float}|null */
    public function for(Supply $supply): ?array
    {
        return $this->all()->get($supply->getKey());
    }

    /**
     * Los que se acaban o bajan del mínimo en las próximas dos semanas y todavía no se pidieron: lo que conviene
     * pedir ya. Los que hoy ya están bajo el mínimo van en "Por pedir".
     *
     * @return Collection<int, array{supply: Supply, runs_out_on: CarbonImmutable|null, below_min_on: CarbonImmutable|null, needed: float}>
     */
    public function runningLow(): Collection
    {
        return $this->all()
            ->filter(fn (array $line) => ($line['runs_out_on'] || $line['below_min_on']) && $line['supply']->onOrder() <= 0 && ! $line['supply']->isLow())
            ->sortBy(fn (array $line) => ($line['runs_out_on'] ?? $line['below_min_on'])->getTimestamp());
    }

    /** "Se acaba ~18/11" o "Baja del mínimo el 16/11", para mostrar junto al material. Null si alcanza. */
    public static function label(?array $line): ?string
    {
        return match (true) {
            $line === null => null,
            $line['runs_out_on'] !== null => 'Se acaba ~'.$line['runs_out_on']->format('d/m').' con las citas agendadas',
            $line['below_min_on'] !== null => 'Baja del mínimo el '.$line['below_min_on']->format('d/m').' con las citas agendadas',
            default => null,
        };
    }

    /** @return Collection<int, array{supply: Supply, runs_out_on: CarbonImmutable|null, below_min_on: CarbonImmutable|null, needed: float}> */
    private function compute(): Collection
    {
        $today = CarbonImmutable::today();

        // Cuántas citas de cada tratamiento hay cada día (las de hoy que todavía no se atendieron también gastan).
        $perDay = Appointment::query()
            ->occupying()
            ->whereNotNull('treatment_id')
            ->whereBetween('starts_at', [now(), $today->addDays(self::HORIZON_DAYS)->endOfDay()])
            ->get(['starts_at', 'treatment_id'])
            ->groupBy(fn (Appointment $appointment) => $appointment->starts_at->toDateString())
            ->map(fn (Collection $day) => $day->countBy('treatment_id'));

        if ($perDay->isEmpty()) {
            return collect();
        }

        $recipes = DB::table('treatment_supplies')
            ->whereIn('treatment_id', $perDay->flatMap(fn (Collection $day) => $day->keys())->unique()->values())
            ->get(['treatment_id', 'supply_id', 'quantity'])
            ->groupBy('supply_id');

        // Solo los materiales cuyo inventario ya se lleva (se cargaron o contaron alguna vez).
        $supplies = Supply::query()
            ->active()
            ->whereKey($recipes->keys())
            ->whereHas('movements')
            ->get()
            ->keyBy('id');

        return $supplies->map(function (Supply $supply) use ($perDay, $recipes) {
            $perTreatment = $recipes->get($supply->getKey())->mapWithKeys(fn (object $row) => [$row->treatment_id => (float) $row->quantity]);
            $left = $supply->usableStock();
            $min = (float) $supply->min_stock;
            $startsLow = $min > 0 && $left < $min;
            [$runsOut, $belowMin, $needed] = [null, null, 0.0];

            foreach ($perDay->sortKeys() as $date => $treatments) {
                $use = $treatments->sum(fn (int $count, int $treatmentId) => $count * ($perTreatment[$treatmentId] ?? 0));

                if ($use <= 0) {
                    continue;
                }

                $needed += $use;
                $left -= $use;
                $day = CarbonImmutable::parse($date);

                if (! $startsLow && $belowMin === null && $min > 0 && $left < $min) {
                    $belowMin = $day;
                }

                if ($runsOut === null && $left < 0) {
                    $runsOut = $day;
                }
            }

            return ['supply' => $supply, 'runs_out_on' => $runsOut, 'below_min_on' => $belowMin, 'needed' => round($needed, 2)];
        })->filter(fn (array $line) => $line['needed'] > 0);
    }
}
