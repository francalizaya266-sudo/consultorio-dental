<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FollowUpsToday;
use App\Filament\Widgets\MonthOverview;
use App\Filament\Widgets\NextPatient;
use App\Filament\Widgets\SystemHealth;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Filament\Widgets\WeekGaps;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Al entrar, cada persona ve lo suyo. Todos: el día y sus citas. La administración: cómo va el mes y
 * si algo del sistema falla. Recepción: a quién escribir y dónde hay huecos. El doctor: su próximo
 * paciente y lo que dejó sin terminar. Cada tarjeta decide si se muestra con el mismo permiso de la
 * pantalla a la que lleva; los gráficos siguen en Reportes.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Hoy';

    protected static ?string $navigationLabel = 'Hoy';

    public function getWidgets(): array
    {
        return [
            SystemHealth::class,
            TodayStats::class,
            NextPatient::class,
            MonthOverview::class,
            TodayAppointments::class,
            FollowUpsToday::class,
            WeekGaps::class,
        ];
    }

    /** Una columna en el celular y la tableta; "Por hacer hoy" y los huecos van lado a lado en pantallas anchas. */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'xl' => 2];
    }
}
