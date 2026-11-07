<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryResource;
use App\Models\User;
use App\Services\Dashboard\WeekGaps as WeekGapsService;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * Los próximos 7 días: qué tan llena está la agenda y dónde quedan huecos para ofrecer
 * (a quien llama, a la lista de espera o a un plan sin agendar). Arriba, cuántas horas libres
 * suman entre todos. Cada día abre la agenda.
 */
class WeekGaps extends Widget
{
    /** Mientras carga, un recuadro del mismo alto: la página no salta. */
    protected ?string $placeholderHeight = '30rem';

    protected string $view = 'filament.widgets.week-gaps';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->managesAllAgendas();
    }

    /**
     * @return array{summary: string|null, days: list<array{label: string, occupancy: int, free: string, url: string, waiting: string|null, waiting_url: string, doctors: list<array{name: string|null, gaps: string}>}>}
     */
    protected function getViewData(): array
    {
        $days = app(WeekGapsService::class)->days();
        $severalDoctors = collect($days)->flatMap(fn (array $day) => array_column($day['doctors'], 'doctor'))->unique('id')->count() > 1;
        $free = array_sum(array_column($days, 'free_minutes'));

        return [
            'summary' => match (true) {
                $days === [] => null,
                $free > 0 => 'Quedan '.Dates::duration($free).' libres de hoy a 7 días. Toca un día para abrir la agenda.',
                default => 'No queda ningún hueco de hoy a 7 días. Toca un día para abrir la agenda.',
            },
            'days' => array_map(fn (array $day) => [
                'label' => match (true) {
                    $day['date']->isToday() => 'Hoy',
                    $day['date']->isTomorrow() => 'Mañana',
                    default => ucfirst($day['date']->translatedFormat('l j')),
                },
                'occupancy' => $day['occupancy'],
                'free' => $day['free_minutes'] > 0 ? Dates::duration($day['free_minutes']).' libres' : 'Sin huecos',
                'url' => Agenda::getUrl(['date' => $day['date']->toDateString(), 'mode' => 'day']),
                'waiting' => match ($day['waiting']) {
                    0 => null,
                    1 => '1 de la lista de espera puede venir',
                    default => $day['waiting'].' de la lista de espera pueden venir',
                },
                'waiting_url' => WaitlistEntryResource::getUrl('index', ['filters' => ['fits' => ['date' => $day['date']->toDateString()]]]),
                'doctors' => array_values(array_filter(array_map(fn (array $row) => $row['gaps'] === [] ? null : [
                    'name' => $severalDoctors ? $row['doctor']->name : null,
                    'gaps' => collect($row['gaps'])->map(fn (array $gap) => self::range($gap['start'], $gap['end']))->implode(' · '),
                ], $day['doctors']))),
            ], $days),
        ];
    }

    private static function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format('H:i').'–'.$end->format('H:i');
    }
}
