<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Tramo de fechas de un reporte, en días completos, y el tramo con el que se compara.
 * "Este mes" va del 1 a hoy y se compara con los mismos días del mes anterior, no con el mes entero.
 */
final class ReportPeriod
{
    /** Opciones del filtro de período. */
    public const PRESETS = [
        'today' => 'Hoy',
        'week' => 'Esta semana',
        'month' => 'Este mes',
        'last_month' => 'Mes pasado',
        'year' => 'Este año',
        'custom' => 'Elegir fechas',
    ];

    /** Hasta este largo se agrupa por día; más largo, por mes. */
    private const DAILY_UP_TO_DAYS = 62;

    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    public function __construct(CarbonInterface $from, CarbonInterface $to)
    {
        [$from, $to] = $from->greaterThan($to) ? [$to, $from] : [$from, $to];

        $this->from = CarbonImmutable::instance($from)->startOfDay();
        $this->to = CarbonImmutable::instance($to)->endOfDay();
    }

    /** @param  array<string, mixed>|null  $filters  Con `period` y, si es "custom", `from` y `to`. */
    public static function fromFilters(?array $filters): self
    {
        $today = CarbonImmutable::today();

        return match ($filters['period'] ?? 'month') {
            'today' => new self($today, $today),
            'week' => new self($today->startOfWeek(), $today),
            'last_month' => new self($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()),
            'year' => new self($today->startOfYear(), $today),
            'custom' => self::custom($filters['from'] ?? null, $filters['to'] ?? null) ?? new self($today->startOfMonth(), $today),
            default => new self($today->startOfMonth(), $today),
        };
    }

    public static function months(int $count): self
    {
        $today = CarbonImmutable::today();

        return new self($today->subMonthsNoOverflow($count - 1)->startOfMonth(), $today);
    }

    private static function custom(mixed $from, mixed $to): ?self
    {
        try {
            return filled($from) && filled($to) ? new self(CarbonImmutable::parse($from), CarbonImmutable::parse($to)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to->startOfDay()) + 1;
    }

    public function groupsByMonth(): bool
    {
        return $this->days() > self::DAILY_UP_TO_DAYS;
    }

    /** El tramo anterior equivalente: los mismos días del mes (o año) anterior, o los días justo antes. */
    public function previous(): self
    {
        $from = $this->from;
        $to = $this->to->startOfDay();

        return match (true) {
            $from->isSameDay($from->startOfYear()) && $from->isSameYear($to) => new self(
                $from->subYear(),
                $to->isSameDay($to->endOfYear()) ? $to->subYear()->endOfYear() : $to->subYearNoOverflow(),
            ),
            $from->day === 1 && $from->isSameMonth($to) => new self(
                $from->subMonthNoOverflow(),
                $to->isSameDay($to->endOfMonth()) ? $to->subMonthNoOverflow()->endOfMonth() : $to->subMonthNoOverflow(),
            ),
            // Un día o una semana: contra los mismos días de la semana pasada.
            $this->days() === 1 || ($from->isMonday() && $this->days() <= 7) => new self($from->subWeek(), $to->subWeek()),
            default => new self($from->subDays($this->days()), $to->subDays($this->days())),
        };
    }

    /** "Octubre de 2026", "Del 1 al 23 de octubre de 2026", "Viernes 23 de octubre de 2026"… */
    public function label(): string
    {
        $from = $this->from;
        $to = $this->to->startOfDay();

        return match (true) {
            $from->isSameDay($to) => ucfirst($from->translatedFormat('l j \d\e F \d\e Y')),
            $this->isWholeYear() => 'Año '.$from->year,
            $this->isWholeMonth() => ucfirst($from->translatedFormat('F \d\e Y')),
            $from->isSameMonth($to) => 'Del '.$from->day.' al '.$to->translatedFormat('j \d\e F \d\e Y'),
            $from->isSameYear($to) => 'Del '.$from->translatedFormat('j \d\e F').' al '.$to->translatedFormat('j \d\e F \d\e Y'),
            default => 'Del '.$from->translatedFormat('j \d\e F \d\e Y').' al '.$to->translatedFormat('j \d\e F \d\e Y'),
        };
    }

    /** Corto, para comparar: "septiembre", "1–23 sep.", "28 sep. – 4 oct.", "2025". */
    public function shortLabel(): string
    {
        $from = $this->from;
        $to = $this->to->startOfDay();
        $year = $to->isCurrentYear() ? '' : ' '.$to->year;

        return match (true) {
            $from->isSameDay($to) => $from->translatedFormat('j M').$year,
            $this->isWholeYear() => (string) $from->year,
            $this->isWholeMonth() => $from->translatedFormat('F').$year,
            $from->isSameMonth($to) => $from->day.'–'.$to->translatedFormat('j M').$year,
            $from->isSameYear($to) => $from->translatedFormat('j M').' – '.$to->translatedFormat('j M').$year,
            default => $from->translatedFormat('j M Y').' – '.$to->translatedFormat('j M Y'),
        };
    }

    /** Para nombres de archivo: "2026-10-01_2026-10-23". */
    public function slug(): string
    {
        return $this->from->toDateString().'_'.$this->to->toDateString();
    }

    private function isWholeMonth(): bool
    {
        return $this->from->day === 1 && $this->to->isSameDay($this->from->endOfMonth());
    }

    private function isWholeYear(): bool
    {
        return $this->from->isSameDay($this->from->startOfYear()) && $this->to->isSameDay($this->from->endOfYear());
    }
}
