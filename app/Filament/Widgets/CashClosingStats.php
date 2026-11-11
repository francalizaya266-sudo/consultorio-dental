<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Services\Billing\CashClosing;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Lo que se cobró en el día, el efectivo que debe haber en caja y lo que entró por otros medios. */
class CashClosingStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Payment::class) ?? false;
    }

    protected function getStats(): array
    {
        $closing = CashClosing::fromFilters($this->pageFilters ?? []);
        $count = $closing->payments()->count();
        $voided = $closing->voided()->count();
        $digital = collect([PaymentMethod::Yape, PaymentMethod::Plin])
            ->map(fn (PaymentMethod $method) => $method->getLabel().' '.Money::format($closing->totalFor($method)))
            ->implode(' · ');

        return [
            Stat::make('Cobrado', Money::format($closing->total()))
                ->description(match ($count) {
                    0 => 'Ningún pago este día',
                    1 => '1 pago',
                    default => $count.' pagos',
                }.($voided > 0 ? ' · '.$voided.' anulado'.($voided > 1 ? 's' : '').' aparte' : ''))
                ->icon('heroicon-o-banknotes'),
            Stat::make('Efectivo en caja', Money::format($closing->cashInDrawer()))
                ->description($closing->cashExpenses() > 0
                    ? Money::format($closing->cash()).' cobrados menos '.Money::format($closing->cashExpenses()).' de gastos'
                    : 'Lo que debe haber al contar')
                ->icon('heroicon-o-calculator'),
            Stat::make('Yape y Plin', Money::format($closing->totalFor(PaymentMethod::Yape) + $closing->totalFor(PaymentMethod::Plin)))
                ->description($digital)
                ->icon('heroicon-o-device-phone-mobile'),
            Stat::make('Tarjeta y otros', Money::format($closing->total() - $closing->cash() - $closing->totalFor(PaymentMethod::Yape) - $closing->totalFor(PaymentMethod::Plin)))
                ->description('Tarjeta, transferencia y otros')
                ->icon('heroicon-o-credit-card'),
        ];
    }
}
