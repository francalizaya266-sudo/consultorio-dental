<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\EarningsTables;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * El cierre de caja de un día: lo que entró por cada medio de pago y lo que recibió cada persona,
 * para cuadrar el efectivo al final del turno. Lo anulado se lista aparte y no suma. Las tablas
 * tienen la forma de las de Ganancias: el panel y la hoja para imprimir las muestran igual.
 */
final class CashClosing
{
    public const NOT_RECORDED = 'Sin registrar';

    /** @var Collection<int, Payment>|null */
    private ?Collection $payments = null;

    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly ?int $receivedBy = null,
    ) {}

    /**
     * El día y la persona elegidos en el panel. Un día que no es válido o que todavía no llega es hoy.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $date = (string) ($filters['date'] ?? '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? CarbonImmutable::parse($date) : null;

        return new self(
            $day && $day->lte(today()) ? $day : CarbonImmutable::today(),
            filled($filters['person'] ?? null) ? (int) $filters['person'] : null,
        );
    }

    /**
     * Quienes registraron pagos ese día (para elegir de quién es el cierre).
     *
     * @return array<int, string>
     */
    public static function receivers(CarbonImmutable $date): array
    {
        return User::query()
            ->whereIn('id', Payment::query()->whereDate('paid_at', $date)->whereNotNull('received_by_user_id')->select('received_by_user_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return Collection<int, Payment> Los pagos válidos del día, en orden. */
    public function payments(): Collection
    {
        return $this->payments ??= $this->query()->whereNull('voided_at')->get();
    }

    /** @return Collection<int, Payment> Los pagos de ese día que después se anularon. */
    public function voided(): Collection
    {
        return $this->query()->whereNotNull('voided_at')->with('voidedBy')->get();
    }

    public function total(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /** Lo que debería haber en efectivo. */
    public function cash(): float
    {
        return $this->totalFor(PaymentMethod::Cash);
    }

    public function totalFor(PaymentMethod $method): float
    {
        return round((float) $this->payments()->where('method', $method)->sum('amount'), 2);
    }

    /** "Martes 3 de noviembre de 2026 · Rosa Pérez". */
    public function label(): string
    {
        $person = $this->receivedBy ? (User::query()->find($this->receivedBy)?->name ?? self::NOT_RECORDED) : null;

        return ucfirst($this->date->translatedFormat('l j \d\e F \d\e Y')).($person ? ' · '.$person : '');
    }

    /**
     * Por medio de pago (el efectivo siempre, porque es lo que se cuenta), por persona (si no se
     * eligió una), los pagos uno por uno y los anulados.
     *
     * @return list<array{key: string, title: string, columns: list<array{label: string, type: string}>, rows: list<list<string|int|float>>, total: list<string|int|float>|null}>
     */
    public function tables(): array
    {
        $payments = $this->payments();

        $methods = collect(PaymentMethod::cases())
            ->filter(fn (PaymentMethod $method) => $method === PaymentMethod::Cash || $payments->contains('method', $method))
            ->map(fn (PaymentMethod $method) => [$method->getLabel(), $payments->where('method', $method)->count(), $this->totalFor($method)])
            ->values()
            ->all();

        $tables = [[
            'key' => 'method',
            'title' => 'Por medio de pago',
            'columns' => [
                ['label' => 'Medio de pago', 'type' => EarningsTables::TEXT],
                ['label' => 'Pagos', 'type' => EarningsTables::COUNT],
                ['label' => 'Cobrado', 'type' => EarningsTables::MONEY],
            ],
            'rows' => $methods,
            'total' => ['Total', $payments->count(), $this->total()],
        ]];

        if ($this->receivedBy === null) {
            $people = $payments
                ->groupBy(fn (Payment $payment) => $payment->receivedBy?->name ?? self::NOT_RECORDED)
                ->sortKeys()
                ->map(fn (Collection $group, string $name) => [
                    $name,
                    round((float) $group->where('method', PaymentMethod::Cash)->sum('amount'), 2),
                    round((float) $group->where('method', '!=', PaymentMethod::Cash)->sum('amount'), 2),
                    round((float) $group->sum('amount'), 2),
                ])
                ->values()
                ->all();

            $tables[] = [
                'key' => 'person',
                'title' => 'Por persona',
                'columns' => [
                    ['label' => 'Recibió', 'type' => EarningsTables::TEXT],
                    ['label' => 'Efectivo', 'type' => EarningsTables::MONEY],
                    ['label' => 'Otros medios', 'type' => EarningsTables::MONEY],
                    ['label' => 'Total', 'type' => EarningsTables::MONEY],
                ],
                'rows' => $people,
                'total' => $people === [] ? null : ['Total', $this->cash(), round($this->total() - $this->cash(), 2), $this->total()],
            ];
        }

        $tables[] = [
            'key' => 'payments',
            'title' => 'Pagos del día',
            'columns' => [
                ['label' => 'Hora', 'type' => EarningsTables::TEXT],
                ['label' => 'Paciente', 'type' => EarningsTables::TEXT],
                ['label' => 'Concepto', 'type' => EarningsTables::TEXT],
                ['label' => 'Medio', 'type' => EarningsTables::TEXT],
                ['label' => 'N.º de operación', 'type' => EarningsTables::TEXT],
                ['label' => 'Recibió', 'type' => EarningsTables::TEXT],
                ['label' => 'Monto', 'type' => EarningsTables::MONEY],
            ],
            'rows' => $payments->map(fn (Payment $payment) => [
                $payment->paid_at->format('H:i'),
                $payment->patient?->full_name ?? '—',
                $payment->treatment?->name ?? 'Pago a cuenta',
                $payment->method->getLabel(),
                $payment->reference ?? '',
                $payment->receivedBy?->name ?? self::NOT_RECORDED,
                (float) $payment->amount,
            ])->all(),
            'total' => $payments->isEmpty() ? null : ['Total', '', '', '', '', '', $this->total()],
        ];

        $voided = $this->voided();

        if ($voided->isNotEmpty()) {
            $tables[] = [
                'key' => 'voided',
                'title' => 'Anulados (no suman)',
                'columns' => [
                    ['label' => 'Hora', 'type' => EarningsTables::TEXT],
                    ['label' => 'Paciente', 'type' => EarningsTables::TEXT],
                    ['label' => 'Medio', 'type' => EarningsTables::TEXT],
                    ['label' => 'Monto', 'type' => EarningsTables::MONEY],
                    ['label' => 'Anuló', 'type' => EarningsTables::TEXT],
                    ['label' => 'Motivo', 'type' => EarningsTables::TEXT],
                ],
                'rows' => $voided->map(fn (Payment $payment) => [
                    $payment->paid_at->format('H:i'),
                    $payment->patient?->full_name ?? '—',
                    $payment->method->getLabel(),
                    (float) $payment->amount,
                    $payment->voidedBy?->name ?? '—',
                    $payment->void_reason ?? '',
                ])->all(),
                'total' => null,
            ];
        }

        return $tables;
    }

    /** @return Builder<Payment> */
    private function query(): Builder
    {
        return Payment::query()
            ->with(['patient', 'treatment', 'receivedBy'])
            ->whereBetween('paid_at', [$this->date->startOfDay(), $this->date->endOfDay()])
            ->when($this->receivedBy, fn (Builder $query, int $id) => $query->where('received_by_user_id', $id))
            ->orderBy('paid_at')
            ->orderBy('id');
    }
}
