<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Earnings;
use App\Filament\Pages\OwingBalances;
use App\Filament\Pages\Summary;
use App\Filament\Support\StatComparison;
use App\Models\User;
use App\Services\Dashboard\MonthFigures;
use App\Services\Reports\ReportAccess;
use App\Support\Money;
use Filament\Schemas\Components\Component;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cómo va el mes, sin entrar a Reportes: lo mismo que Resumen e Ingresos con el filtro "Este mes".
 * Lo ve quien ve los reportes; un doctor, solo lo suyo. La meta y lo que se debe son del consultorio entero.
 */
class MonthOverview extends StatsOverviewWidget
{
    /** Mientras carga, un recuadro del mismo alto: la página no salta. */
    protected ?string $placeholderHeight = '18rem';

    protected ?string $pollingInterval = null;

    /** De a dos en el celular (las cifras se achican), de a tres en pantallas anchas. */
    protected int|array|null $columns = ['default' => 2, '@xl' => 3, '!@lg' => 3];

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return ReportAccess::canView($user);
    }

    public function getSectionContentComponent(): Component
    {
        return parent::getSectionContentComponent()->extraAttributes(['class' => 'dash-month']);
    }

    protected function getHeading(): ?string
    {
        return ucfirst(today()->translatedFormat('F')).' hasta hoy';
    }

    protected function getDescription(): ?string
    {
        return ($this->doctorId() !== null ? 'Solo tus citas y lo que se cobró de ellas. ' : '')
            .'Comparado con los mismos días del mes pasado.'
            .($this->isEarly() ? ' Con tan pocos días, las diferencias todavía no dicen mucho.' : '');
    }

    protected function getStats(): array
    {
        $month = new MonthFigures($this->doctorId());
        $figures = $month->figures();
        $before = $month->previousPeriod;
        $count = fn (float $n) => number_format($n);

        $comparisons = [
            StatComparison::make('Cobrado', $figures['collected'], $figures['collected_before'], $before, Money::format(...))
                ->icon('heroicon-o-banknotes')
                ->chart($figures['daily_collected'])
                ->url(Earnings::getUrl()),
            StatComparison::make('Realizado', $figures['produced'], $figures['produced_before'], $before, Money::format(...))
                ->icon('heroicon-o-clipboard-document-check')
                ->url(Earnings::getUrl()),
            StatComparison::make('Citas atendidas', $figures['attended'], $figures['attended_before'], $before, $count)
                ->icon('heroicon-o-check-badge')
                ->url(Summary::getUrl()),
            StatComparison::points('Inasistencias', $figures['no_show_rate'], $figures['no_show_rate_before'], $before, higherIsBetter: false)
                ->icon('heroicon-o-user-minus')
                ->url(Summary::getUrl()),
        ];

        return array_values(array_filter([
            ...$comparisons,
            $this->doctorId() === null ? $this->outstanding() : null,
            $this->doctorId() === null ? $this->goal($figures['collected']) : null,
        ]));
    }

    private function isEarly(): bool
    {
        return today()->day < MonthFigures::SETTLED_FROM_DAY;
    }

    /** Un doctor ve lo suyo; la administración, todo el consultorio. */
    private function doctorId(): ?int
    {
        return ReportAccess::doctorFor(auth()->user(), null);
    }

    private function outstanding(): Stat
    {
        return Stat::make('Por cobrar (a hoy)', Money::format(MonthFigures::outstanding()))
            ->description('Saldos pendientes de todos los pacientes')
            ->icon('heroicon-o-scale')
            ->url(OwingBalances::getUrl());
    }

    /** La meta de cobro del mes, si se puso en Ajustes. */
    private function goal(float $collected): ?Stat
    {
        $progress = MonthFigures::goalProgress($collected);

        if ($progress === null) {
            return null;
        }

        $goal = Money::short($progress['goal']);
        $description = match (true) {
            $progress['remaining'] <= 0 => 'Cumplida la meta de '.$goal,
            $progress['days_left'] === 0 => 'De '.$goal.' · faltan '.Money::short($progress['remaining']).' y hoy cierra el mes',
            default => 'De '.$goal.' · faltan '.Money::short($progress['remaining']).' en '.$progress['days_left'].($progress['days_left'] === 1 ? ' día' : ' días')
                .($progress['on_track'] ? ', a buen ritmo' : ', por debajo del ritmo'),
        };

        return Stat::make('Meta del mes', $progress['percent'].' %')
            ->description($description)
            ->descriptionIcon($progress['on_track'] ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($progress['on_track'] ? 'success' : 'warning')
            ->icon('heroicon-o-flag');
    }
}
