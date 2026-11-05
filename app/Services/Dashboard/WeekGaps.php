<?php

namespace App\Services\Dashboard;

use App\Models\Doctor;
use App\Services\Agenda\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * Los próximos días con atención: qué parte del horario ya está ocupada y los huecos donde aún
 * entra una cita, por doctor. Hoy cuenta desde ahora. Usa el mismo cálculo que la agenda inteligente.
 */
final class WeekGaps
{
    public const DAYS = 7;

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @return list<array{date: CarbonImmutable, occupancy: int, free_minutes: int, doctors: list<array{doctor: Doctor, free_minutes: int, gaps: list<array{start: CarbonImmutable, end: CarbonImmutable}>}>}>
     */
    public function days(): array
    {
        $doctors = Doctor::query()->active()->orderBy('sort_order')->orderBy('name')->get();
        $days = [];

        for ($date = CarbonImmutable::today(), $offset = 0; $offset < self::DAYS; $offset++, $date = $date->addDay()) {
            $open = 0;
            $free = 0;
            $rows = [];

            foreach ($doctors as $doctor) {
                $day = $this->availability->dayGaps($doctor, $date, $date->isToday() ? now() : null);

                if ($day['open'] === 0) {
                    continue;
                }

                $open += $day['open'];
                $free += $day['free'];
                $rows[] = [
                    'doctor' => $doctor,
                    'free_minutes' => self::minutes($day['gaps']),
                    'gaps' => $day['gaps'],
                ];
            }

            if ($rows === []) {
                continue;
            }

            $days[] = [
                'date' => $date,
                'occupancy' => (int) min(100, max(0, round(($open - $free) / $open * 100))),
                'free_minutes' => array_sum(array_column($rows, 'free_minutes')),
                'doctors' => $rows,
            ];
        }

        return $days;
    }

    /** @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $gaps */
    private static function minutes(array $gaps): int
    {
        return (int) array_sum(array_map(fn (array $gap) => $gap['start']->diffInMinutes($gap['end']), $gaps));
    }
}
