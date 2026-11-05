<?php

namespace App\Services\Dashboard;

use App\Models\Setting;
use App\Services\Reports\ActivityReport;
use App\Services\Reports\EarningsReport;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * El mes hasta hoy para el inicio: lo cobrado y lo realizado contra los mismos días del mes pasado,
 * las citas atendidas, las inasistencias, lo cobrado día por día en los últimos 30 días y lo
 * acumulado de este mes contra el mes pasado entero.
 * Sale de los mismos reportes que la pantalla Reportes, así las cifras coinciden.
 * Se guarda 5 minutos por doctor (o para todos), para que "Hoy" abra rápido.
 */
final class MonthFigures
{
    public const CACHE_SECONDS = 300;

    /** Antes de este día del mes, las comparaciones y la proyección se basan en muy poco. */
    public const SETTLED_FROM_DAY = 7;

    private const TREND_DAYS = 30;

    public readonly ReportPeriod $period;

    public readonly ReportPeriod $previousPeriod;

    /** @param  int|null  $doctorId  Solo lo de ese doctor; null es todo el consultorio. */
    public function __construct(public readonly ?int $doctorId = null)
    {
        $this->period = ReportPeriod::fromFilters(['period' => 'month']);
        $this->previousPeriod = $this->period->previous();
    }

    /**
     * @return array{collected: float, collected_before: float, produced: float, produced_before: float, attended: int, attended_before: int, no_show_rate: float|null, no_show_rate_before: float|null, daily_collected: list<float>}
     */
    public function figures(): array
    {
        return Cache::remember(
            'dashboard.month.'.today()->toDateString().'.'.($this->doctorId ?? 'all'),
            self::CACHE_SECONDS,
            fn () => $this->compute(),
        );
    }

    /**
     * Lo cobrado acumulado día por día: este mes hasta hoy y el mes pasado entero, para ver si
     * este mes viene por encima o por debajo. La posición 0 es el día 1.
     *
     * @return array{this_month: list<float>, last_month: list<float>}
     */
    public function cumulative(): array
    {
        return Cache::remember(
            'dashboard.cumulative.'.today()->toDateString().'.'.($this->doctorId ?? 'all'),
            self::CACHE_SECONDS,
            fn () => [
                'this_month' => $this->runningTotal($this->period),
                'last_month' => $this->runningTotal(ReportPeriod::fromFilters(['period' => 'last_month'])),
            ],
        );
    }

    /**
     * A cuánto llegaría el mes si sigue al ritmo de los días que van. Null los primeros días,
     * cuando un solo pago grande (o ninguno) cambia todo.
     */
    public static function projection(float $collected): ?float
    {
        $today = CarbonImmutable::today();

        return $today->day < self::SETTLED_FROM_DAY ? null : round($collected / $today->day * $today->daysInMonth, 2);
    }

    /** Lo que deben hoy todos los pacientes juntos. */
    public static function outstanding(): float
    {
        return Cache::remember('dashboard.outstanding', self::CACHE_SECONDS, fn () => EarningsReport::outstanding());
    }

    /**
     * Avance hacia la meta de cobro del mes que se pone en Ajustes. "A tiempo" si lo cobrado
     * va al menos al ritmo de los días que pasaron del mes. Null si no hay meta.
     *
     * @return array{goal: float, percent: int, remaining: float, days_left: int, on_track: bool}|null
     */
    public static function goalProgress(float $collected): ?array
    {
        $goal = (float) Setting::get('reports.monthly_goal');

        if ($goal <= 0) {
            return null;
        }

        $today = CarbonImmutable::today();
        $expectedByToday = $goal * $today->day / $today->daysInMonth;

        return [
            'goal' => $goal,
            'percent' => (int) floor($collected / $goal * 100),
            'remaining' => max(0.0, round($goal - $collected, 2)),
            'days_left' => $today->daysInMonth - $today->day,
            'on_track' => $collected >= $expectedByToday,
        ];
    }

    /** @return list<float> */
    private function runningTotal(ReportPeriod $period): array
    {
        $total = 0.0;

        return array_map(
            function (float $collected) use (&$total) {
                $total += $collected;

                return round($total, 2);
            },
            array_column((new EarningsReport($period, $this->doctorId))->byPeriod(withEmpty: true), 'collected'),
        );
    }

    /**
     * @return array{collected: float, collected_before: float, produced: float, produced_before: float, attended: int, attended_before: int, no_show_rate: float|null, no_show_rate_before: float|null, daily_collected: list<float>}
     */
    private function compute(): array
    {
        $earnings = new EarningsReport($this->period, $this->doctorId);
        $earningsBefore = new EarningsReport($this->previousPeriod, $this->doctorId);
        $activity = new ActivityReport($this->period, $this->doctorId);
        $activityBefore = new ActivityReport($this->previousPeriod, $this->doctorId);
        $today = CarbonImmutable::today();
        $trend = new EarningsReport(new ReportPeriod($today->subDays(self::TREND_DAYS - 1), $today), $this->doctorId);

        return [
            'collected' => $earnings->collected(),
            'collected_before' => $earningsBefore->collected(),
            'produced' => $earnings->produced(),
            'produced_before' => $earningsBefore->produced(),
            'attended' => $activity->attended(),
            'attended_before' => $activityBefore->attended(),
            'no_show_rate' => $activity->noShowRate(),
            'no_show_rate_before' => $activityBefore->noShowRate(),
            'daily_collected' => array_column($trend->byPeriod(withEmpty: true), 'collected'),
        ];
    }
}
