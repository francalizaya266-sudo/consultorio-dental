<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Services\Billing\CashClosing;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Las tablas del cierre (las mismas de la hoja para imprimir). */
class CashClosingTables extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.cash-closing-tables';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Payment::class) ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['tables' => CashClosing::fromFilters($this->pageFilters ?? [])->tables()];
    }
}
