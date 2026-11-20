<?php

namespace App\Filament\Widgets;

use App\Enums\TodayView;
use App\Filament\Concerns\RefreshesWithToday;
use App\Filament\Pages\Dashboard;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CashClosing;
use App\Support\Dates;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * El selector "Mi consulta / Toda la clínica", solo para el administrador que también atiende. En "Mi consulta",
 * al lado, una línea discreta con lo que se acumula en la clínica (quién está en sala, lo que falta cobrar) que
 * lleva a "Toda la clínica": así no hace falta cambiar de vista para saber si algo necesita a alguien.
 */
class TodayViewSwitch extends Widget
{
    use RefreshesWithToday;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.today-view-switch';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return TodayView::canChoose(auth()->user());
    }

    /** @return array{views: list<array{label: string, url: string, active: bool}>, clinic: list<array{text: string, alert: bool}>, clinicUrl: string} */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $current = TodayView::current($user);

        return [
            'views' => array_map(fn (TodayView $view) => [
                'label' => $view->getLabel(),
                'url' => Dashboard::getUrl([TodayView::QUERY => $view->value]),
                'active' => $view === $current,
            ], TodayView::cases()),
            'clinic' => $current === TodayView::Mine ? $this->clinicAtAGlance($user) : [],
            'clinicUrl' => Dashboard::getUrl([TodayView::QUERY => TodayView::Clinic->value]),
        ];
    }

    /** @return list<array{text: string, alert: bool}> "3 en sala" (en rojo si alguien espera de más) y "S/ 150 por cobrar". */
    private function clinicAtAGlance(User $user): array
    {
        $waits = WaitingRoom::waitingMinutes();
        $parts = [[
            'text' => match ($waits->count()) {
                0 => 'nadie en sala',
                default => $waits->count().' en sala'.($waits->max() >= TodayAppointments::LONG_WAIT_MINUTES ? ', uno espera '.Dates::duration($waits->max()) : ''),
            },
            'alert' => $waits->max() >= TodayAppointments::LONG_WAIT_MINUTES,
        ]];

        if ($user->can('viewAny', Payment::class)) {
            $owing = (new CashClosing(CarbonImmutable::today()))->pendingTotal();

            if ($owing > 0) {
                $parts[] = ['text' => Money::format($owing).' por cobrar', 'alert' => false];
            }
        }

        return $parts;
    }
}
