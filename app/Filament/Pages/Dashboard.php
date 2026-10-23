<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/** Al entrar, cada persona ve su día: citas, avisos por enviar y pendientes. */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Hoy';

    protected static ?string $navigationLabel = 'Hoy';
}
