<?php

namespace App\Services\Reports;

use App\Support\Money;

/**
 * Las tablas de ganancias, una sola vez: el panel, la hoja para imprimir y el Excel muestran lo mismo.
 * Cada celda guarda el valor sin formato; quien la muestra le da formato según el tipo de su columna.
 */
final class EarningsTables
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const COUNT = 'count';

    public const PERCENT = 'percent';

    /**
     * @return list<array{key: string, title: string, columns: list<array{label: string, type: string}>, rows: list<list<string|int|float>>, total: list<string|int|float>|null}>
     */
    public static function for(EarningsReport $report): array
    {
        $tables = [];

        $periods = $report->byPeriod();
        $tables[] = [
            'key' => 'period',
            'title' => $report->period->groupsByMonth() ? 'Por mes' : 'Por día',
            'columns' => [
                ['label' => $report->period->groupsByMonth() ? 'Mes' : 'Día', 'type' => self::TEXT],
                ['label' => 'Cobrado', 'type' => self::MONEY],
                ['label' => 'Realizado', 'type' => self::MONEY],
                ['label' => 'Pagos', 'type' => self::COUNT],
            ],
            'rows' => array_map(fn (array $row) => [$row['label'], $row['collected'], $row['produced'], $row['payments']], $periods),
            'total' => ['Total', self::sum($periods, 'collected'), self::sum($periods, 'produced'), array_sum(array_column($periods, 'payments'))],
        ];

        // Con un solo doctor elegido, la tabla por doctor no dice nada nuevo.
        if ($report->doctorId === null) {
            $doctors = $report->byDoctor();
            $tables[] = [
                'key' => 'doctor',
                'title' => 'Por doctor',
                'columns' => [
                    ['label' => 'Doctor', 'type' => self::TEXT],
                    ['label' => 'Realizado', 'type' => self::MONEY],
                    ['label' => 'Cobrado', 'type' => self::MONEY],
                ],
                'rows' => array_map(fn (array $row) => [$row['name'], $row['produced'], $row['collected']], $doctors),
                'total' => ['Total', self::sum($doctors, 'produced'), self::sum($doctors, 'collected')],
            ];
        }

        $treatments = $report->byTreatment();
        $tables[] = [
            'key' => 'treatment',
            'title' => 'Por tratamiento',
            'columns' => [
                ['label' => 'Tratamiento', 'type' => self::TEXT],
                ['label' => 'Veces', 'type' => self::COUNT],
                ['label' => 'Realizado', 'type' => self::MONEY],
            ],
            'rows' => array_map(fn (array $row) => [$row['name'], $row['count'], $row['produced']], $treatments),
            'total' => ['Total', array_sum(array_column($treatments, 'count')), self::sum($treatments, 'produced')],
        ];

        $methods = $report->byMethod();
        $tables[] = [
            'key' => 'method',
            'title' => 'Por medio de pago',
            'columns' => [
                ['label' => 'Medio de pago', 'type' => self::TEXT],
                ['label' => 'Pagos', 'type' => self::COUNT],
                ['label' => 'Cobrado', 'type' => self::MONEY],
                ['label' => 'Parte', 'type' => self::PERCENT],
            ],
            'rows' => array_map(fn (array $row) => [$row['label'], $row['payments'], $row['collected'], $row['share']], $methods),
            'total' => $methods === [] ? null : ['Total', array_sum(array_column($methods, 'payments')), self::sum($methods, 'collected'), 100.0],
        ];

        return array_map(fn (array $table) => $table['rows'] === [] ? [...$table, 'total' => null] : $table, $tables);
    }

    /** Una celda lista para leer: "S/ 1,250.00", "12", "35.5 %". */
    public static function format(string|int|float $value, string $type): string
    {
        return match ($type) {
            self::MONEY => Money::format($value),
            self::COUNT => number_format((int) $value),
            self::PERCENT => rtrim(rtrim(number_format((float) $value, 1), '0'), '.').' %',
            default => (string) $value,
        };
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function sum(array $rows, string $key): float
    {
        return round(array_sum(array_column($rows, $key)), 2);
    }
}
