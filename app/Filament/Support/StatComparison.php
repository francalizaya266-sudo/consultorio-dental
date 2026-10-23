<?php

namespace App\Filament\Support;

use App\Services\Reports\ReportPeriod;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Una cifra del período con su cambio frente al período anterior: "+12 % vs. 1–23 sep.". */
final class StatComparison
{
    /**
     * @param  callable(float): string  $format
     * @param  bool  $higherIsBetter  Para inasistencias, subir es malo.
     */
    public static function make(string $label, float $current, float $previous, ReportPeriod $previousPeriod, callable $format, bool $higherIsBetter = true): Stat
    {
        $stat = Stat::make($label, $format($current));
        $against = $previousPeriod->shortLabel();

        if ($previous <= 0) {
            return $stat->description($format(0).' en '.$against)->color('gray');
        }

        $change = round(($current - $previous) / $previous * 100);

        return self::trend($stat, $change, abs($change).' %', $against, $higherIsBetter);
    }

    /** Para porcentajes: el cambio va en puntos ("+3 puntos"), no en porcentaje de porcentaje. */
    public static function points(string $label, ?float $current, ?float $previous, ReportPeriod $previousPeriod, bool $higherIsBetter = true): Stat
    {
        $stat = Stat::make($label, $current === null ? '—' : self::percent($current));
        $against = $previousPeriod->shortLabel();

        if ($current === null || $previous === null) {
            return $stat->description($previous === null ? 'Sin citas en '.$against : self::percent($previous).' en '.$against)->color('gray');
        }

        $change = round($current - $previous, 1);

        return self::trend($stat, $change, self::number(abs($change)).(abs($change) === 1.0 ? ' punto' : ' puntos'), $against, $higherIsBetter);
    }

    public static function percent(float $value): string
    {
        return self::number($value).' %';
    }

    private static function trend(Stat $stat, float $change, string $amount, string $against, bool $higherIsBetter): Stat
    {
        if ($change == 0) {
            return $stat->description('Igual que en '.$against)->color('gray');
        }

        $up = $change > 0;

        return $stat
            ->description(($up ? '+' : '−').$amount.' vs. '.$against)
            ->descriptionIcon($up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($up === $higherIsBetter ? 'success' : 'danger');
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
