<?php

namespace App\Services\Dashboard;

use App\Models\Doctor;
use App\Models\WaitlistEntry;
use App\Services\Agenda\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * Los próximos días con atención: qué parte del horario ya está ocupada y los huecos donde aún
 * entra una cita, por doctor, y cuántos de la lista de espera pueden venir ese día para ofrecérselos.
 * Hoy cuenta desde ahora. Usa el mismo cálculo que la agenda inteligente.
 */
final class WeekGaps
{
    public const DAYS = 7;

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @return list<array{date: CarbonImmutable, occupancy: int, free_minutes: int, waiting: int, doctors: list<array{doctor: Doctor, free_minutes: int, gaps: list<array{start: CarbonImmutable, end: CarbonImmutable}>}>}>
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

            $withGaps = array_values(array_filter($rows, fn (array $row) => $row['gaps'] !== []));

            $days[] = [
                'date' => $date,
                'occupancy' => (int) min(100, max(0, round(($open - $free) / $open * 100))),
                'free_minutes' => array_sum(array_column($rows, 'free_minutes')),
                'waiting' => $withGaps === [] ? 0 : $this->waiting($date, array_map(fn (array $row) => $row['doctor']->getKey(), $withGaps)),
                'doctors' => $rows,
            ];
        }

        return $days;
    }

    /**
     * De la lista de espera, quienes pueden venir ese día con alguno de los doctores que tienen huecos.
     *
     * @param  list<int>  $doctorIds
     */
    private function waiting(CarbonImmutable $date, array $doctorIds): int
    {
        return WaitlistEntry::query()
            ->availableOn($date)
            ->whereHas('patient')
            ->where(fn ($query) => $query->whereNull('doctor_id')->orWhereIn('doctor_id', $doctorIds))
            ->count();
    }

    /** @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $gaps */
    private static function minutes(array $gaps): int
    {
        return (int) array_sum(array_map(fn (array $gap) => $gap['start']->diffInMinutes($gap['end']), $gaps));
    }
}
