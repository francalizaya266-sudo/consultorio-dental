<?php

namespace App\Services\Reports;

use App\Enums\PaymentMethod;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ganancias de un período. Dos miradas que no siempre coinciden:
 * - Cobrado: los pagos que entraron a caja (lo que importa para cuadrar la caja).
 * - Realizado: lo atendido, según los cargos (lo que dejó cada tratamiento y cada doctor, se haya pagado o no).
 * Nada anulado cuenta. Con $doctorId, solo lo de ese doctor.
 */
final class EarningsReport
{
    /** Concepto sin tratamiento del catálogo (un trabajo de laboratorio, un cargo manual). */
    public const OTHER_CONCEPTS = 'Otros conceptos';

    public const NO_DOCTOR = 'Sin doctor asignado';

    public function __construct(
        public readonly ReportPeriod $period,
        public readonly ?int $doctorId = null,
    ) {}

    /** @return Builder<Payment> */
    public function payments(): Builder
    {
        return Payment::query()
            ->whereNull('voided_at')
            ->whereBetween('paid_at', [$this->period->from, $this->period->to])
            ->when($this->doctorId, fn (Builder $query, int $id) => $query->where('doctor_id', $id));
    }

    /** @return Builder<Charge> */
    public function charges(): Builder
    {
        return Charge::query()
            ->valid()
            ->whereBetween('charged_at', [$this->period->from, $this->period->to])
            ->when($this->doctorId, fn (Builder $query, int $id) => $query->where('doctor_id', $id));
    }

    public function collected(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function paymentsCount(): int
    {
        return $this->payments()->count();
    }

    public function produced(): float
    {
        return round((float) $this->charges()->sum(DB::raw('amount - discount')), 2);
    }

    /** Lo que deben hoy todos los pacientes juntos (no depende del período). */
    public static function outstanding(): float
    {
        $owing = Patient::query()->owing()->select('patients.id')->withBalance();

        return round((float) DB::query()->fromSub($owing, 'owing')->sum('balance_due'), 2);
    }

    /**
     * Día por día (o mes por mes, si el período es largo). Con $withEmpty incluye los días sin movimiento.
     *
     * @return list<array{start: CarbonImmutable, label: string, collected: float, produced: float, payments: int}>
     */
    public function byPeriod(bool $withEmpty = false): array
    {
        $collected = $this->payments()
            ->selectRaw('date(paid_at) as day, sum(amount) as total, count(*) as count')
            ->groupByRaw('date(paid_at)')
            ->toBase()
            ->get()
            ->keyBy('day');

        $produced = $this->charges()
            ->selectRaw('date(charged_at) as day, sum(amount - discount) as total')
            ->groupByRaw('date(charged_at)')
            ->toBase()
            ->get()
            ->keyBy('day');

        $byMonth = $this->period->groupsByMonth();
        $buckets = [];

        for ($day = $this->period->from; $day->lte($this->period->to); $day = $day->addDay()) {
            $start = $byMonth ? $day->startOfMonth() : $day;
            $key = $start->toDateString();
            $buckets[$key] ??= [
                'start' => $start,
                'label' => $byMonth ? ucfirst($start->translatedFormat('F Y')) : ucfirst($start->translatedFormat('D j M')),
                'collected' => 0.0,
                'produced' => 0.0,
                'payments' => 0,
            ];

            $date = $day->toDateString();
            $buckets[$key]['collected'] += (float) ($collected[$date]->total ?? 0);
            $buckets[$key]['produced'] += (float) ($produced[$date]->total ?? 0);
            $buckets[$key]['payments'] += (int) ($collected[$date]->count ?? 0);
        }

        return collect($buckets)
            ->map(fn (array $row) => [...$row, 'collected' => round($row['collected'], 2), 'produced' => round($row['produced'], 2)])
            ->when(! $withEmpty, fn (Collection $rows) => $rows->filter(fn (array $row) => $row['collected'] > 0 || $row['produced'] > 0 || $row['payments'] > 0))
            ->values()
            ->all();
    }

    /** @return list<array{name: string, produced: float, collected: float}> */
    public function byDoctor(): array
    {
        $produced = $this->charges()->selectRaw('doctor_id, sum(amount - discount) as total')->groupBy('doctor_id')->toBase()->pluck('total', 'doctor_id');
        $collected = $this->payments()->selectRaw('doctor_id, sum(amount) as total')->groupBy('doctor_id')->toBase()->pluck('total', 'doctor_id');
        $names = Doctor::query()->whereKey($produced->keys()->merge($collected->keys())->filter()->unique())->pluck('name', 'id');

        return $produced->keys()->merge($collected->keys())->unique()
            ->map(fn ($id) => [
                'name' => $id ? ($names[$id] ?? self::NO_DOCTOR) : self::NO_DOCTOR,
                'produced' => round((float) ($produced[$id] ?? 0), 2),
                'collected' => round((float) ($collected[$id] ?? 0), 2),
            ])
            ->sortBy([['produced', 'desc'], ['collected', 'desc']])
            ->values()
            ->all();
    }

    /**
     * Lo realizado por tratamiento: cuánto dejó y cuántas veces se hizo (cada sesión cuenta).
     *
     * @return list<array{name: string, produced: float, count: int}>
     */
    public function byTreatment(): array
    {
        $rows = $this->charges()
            ->selectRaw('treatment_id, sum(amount - discount) as total, count(*) as count')
            ->groupBy('treatment_id')
            ->toBase()
            ->get();
        $names = Treatment::query()->whereKey($rows->pluck('treatment_id')->filter())->pluck('name', 'id');

        return $rows
            ->groupBy(fn (object $row) => $row->treatment_id ? ($names[$row->treatment_id] ?? self::OTHER_CONCEPTS) : self::OTHER_CONCEPTS)
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'produced' => round((float) $group->sum('total'), 2),
                'count' => (int) $group->sum('count'),
            ])
            // "Otros conceptos" va al final aunque sume más.
            ->sortBy([fn (array $a, array $b) => ($a['name'] === self::OTHER_CONCEPTS) <=> ($b['name'] === self::OTHER_CONCEPTS), ['produced', 'desc']])
            ->values()
            ->all();
    }

    /** @return list<array{method: PaymentMethod, label: string, collected: float, payments: int, share: float}> */
    public function byMethod(): array
    {
        $rows = $this->payments()
            ->selectRaw('method, sum(amount) as total, count(*) as count')
            ->groupBy('method')
            ->toBase()
            ->get();
        $total = (float) $rows->sum('total');

        return $rows
            ->map(fn (object $row) => [
                'method' => $method = PaymentMethod::from($row->method),
                'label' => $method->getLabel(),
                'collected' => round((float) $row->total, 2),
                'payments' => (int) $row->count,
                'share' => $total > 0 ? round((float) $row->total / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('collected')
            ->values()
            ->all();
    }
}
