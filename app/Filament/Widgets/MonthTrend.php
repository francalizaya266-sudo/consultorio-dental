<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Reports\ReportChart;
use App\Models\Setting;
use App\Services\Dashboard\MonthFigures;
use App\Services\Reports\ReportAccess;
use App\Support\Money;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Lo cobrado en el mes, acumulado día por día, contra el mes pasado y contra el ritmo que pide la
 * meta. Debajo del título, a cuánto llegaría el mes si sigue así. Un doctor ve solo lo suyo y sin
 * la meta, que es del consultorio entero.
 */
class MonthTrend extends ReportChart
{
    /** Gris neutro que se lee igual en claro y oscuro: el mes pasado es contexto, no protagonista. */
    private const MUTED = '#a1a1aa';

    /** Mientras carga, un recuadro del mismo alto: la página no salta. */
    protected ?string $placeholderHeight = '20rem';

    protected string $unit = 'money';

    protected string $tone = 'money';

    protected ?string $maxHeight = '240px';

    protected string $hoverMode = 'index';

    protected string $tooltipTitlePrefix = 'Día ';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'Cobrado en '.today()->translatedFormat('F').', día a día';
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return 'Todavía no hay cobros este mes';
    }

    protected function chartData(): array
    {
        $series = $this->figures()->cumulative();
        $days = today()->daysInMonth;
        $goal = $this->goal();

        return [
            'labels' => array_map(strval(...), range(1, $days)),
            'datasets' => array_values(array_filter([
                [
                    'label' => ucfirst(today()->translatedFormat('F')),
                    'data' => array_pad($series['this_month'], $days, null),
                    'fill' => 'origin',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
                    'borderWidth' => 2.5,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 5,
                    'order' => 0,
                ],
                $goal === null ? null : [
                    'label' => 'Ritmo para la meta',
                    'data' => array_map(fn (int $day) => round($goal * $day / $days, 2), range(1, $days)),
                    'borderDash' => [6, 4],
                    'borderWidth' => 1.5,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 0,
                    'order' => 1,
                ],
                [
                    'label' => ucfirst(today()->subMonthNoOverflow()->translatedFormat('F')),
                    'data' => array_slice(array_pad($series['last_month'], $days, null), 0, $days),
                    'borderColor' => self::MUTED,
                    'backgroundColor' => self::MUTED,
                    'pointBackgroundColor' => self::MUTED,
                    'borderWidth' => 1.5,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'order' => 2,
                ],
            ])),
        ];
    }

    /** A cuánto cerraría el mes, comparado con la meta o, si no hay, con el mes pasado. */
    protected function finding(): ?string
    {
        $series = $this->figures()->cumulative();
        $collected = (float) (end($series['this_month']) ?: 0);
        $projection = MonthFigures::projection($collected);
        $mine = $this->doctorId() !== null ? 'Solo lo tuyo. ' : '';

        if ($projection === null) {
            $days = today()->day;

            return $mine.'Van '.Money::format($collected).' en '.$days.($days === 1 ? ' día' : ' días')
                .'. Desde el día '.MonthFigures::SETTLED_FROM_DAY.' te digo a cuánto cerraría el mes.';
        }

        $goal = $this->goal();
        $lastMonth = (float) (end($series['last_month']) ?: 0);
        $against = match (true) {
            $goal !== null => $projection >= $goal
                ? ', por encima de la meta de '.Money::short($goal)
                : ', por debajo de la meta de '.Money::short($goal),
            $lastMonth > 0 => '; '.today()->subMonthNoOverflow()->translatedFormat('F').' cerró en '.Money::format($lastMonth),
            default => '',
        };

        return $mine.'A este ritmo, '.today()->translatedFormat('F').' cerraría en '.Money::format($projection).$against.'.';
    }

    private function figures(): MonthFigures
    {
        return new MonthFigures($this->doctorId());
    }

    /** Un doctor ve lo suyo; la administración, todo el consultorio. */
    private function doctorId(): ?int
    {
        return ReportAccess::doctorFor(auth()->user(), null);
    }

    /** La meta de cobro del mes es del consultorio: no se dibuja en la vista de un doctor. */
    private function goal(): ?float
    {
        $goal = (float) Setting::get('reports.monthly_goal');

        return $this->doctorId() === null && $goal > 0 ? $goal : null;
    }
}
