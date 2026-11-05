<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Agenda;
use App\Models\User;
use App\Services\Dashboard\WeekGaps as WeekGapsService;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * Los próximos 7 días: qué tan llena está la agenda y dónde quedan huecos para ofrecer
 * (a quien llama, a la lista de espera o a un plan sin agendar). Cada día abre la agenda.
 */
class WeekGaps extends Widget
{
    protected static ?int $sort = 7;

    protected string $view = 'filament.widgets.week-gaps';

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->managesAllAgendas();
    }

    /**
     * @return array{days: list<array{label: string, occupancy: int, free: string, url: string, doctors: list<array{name: string|null, gaps: string}>}>}
     */
    protected function getViewData(): array
    {
        $days = app(WeekGapsService::class)->days();
        $severalDoctors = collect($days)->flatMap(fn (array $day) => array_column($day['doctors'], 'doctor'))->unique('id')->count() > 1;

        return ['days' => array_map(fn (array $day) => [
            'label' => match (true) {
                $day['date']->isToday() => 'Hoy',
                $day['date']->isTomorrow() => 'Mañana',
                default => ucfirst($day['date']->translatedFormat('l j')),
            },
            'occupancy' => $day['occupancy'],
            'free' => $day['free_minutes'] > 0 ? Dates::duration($day['free_minutes']).' libres' : 'Sin huecos',
            'url' => Agenda::getUrl(['date' => $day['date']->toDateString(), 'mode' => 'day']),
            'doctors' => array_values(array_filter(array_map(fn (array $row) => $row['gaps'] === [] ? null : [
                'name' => $severalDoctors ? $row['doctor']->name : null,
                'gaps' => collect($row['gaps'])->map(fn (array $gap) => self::range($gap['start'], $gap['end']))->implode(' · '),
            ], $day['doctors']))),
        ], $days)];
    }

    private static function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format('H:i').'–'.$end->format('H:i');
    }
}
