<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use Filament\Pages\Dashboard as BaseDashboard;

/** Al entrar, cada persona ve su día: citas, avisos por enviar y pendientes. */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Hoy';

    protected static ?string $navigationLabel = 'Hoy';

    /** Solo los del día: los gráficos y las ganancias tienen sus propias páginas. */
    public function getWidgets(): array
    {
        return [TodayStats::class, TodayAppointments::class];
    }
}
