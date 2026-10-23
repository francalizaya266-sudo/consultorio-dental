<?php

namespace App\Services\Web;

use App\Models\DoctorSchedule;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Horario de atención del consultorio, armado con los horarios de los doctores activos:
 * así nunca queda desactualizado respecto de la agenda.
 */
final class OpeningHours
{
    public const DAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /** @param  array<int, list<array{0: string, 1: string}>>  $week  Día ISO => tramos "H:i" ya unidos. */
    public function __construct(private readonly array $week) {}

    public static function fromSchedules(?CarbonImmutable $on = null): self
    {
        $on ??= CarbonImmutable::today();

        $rows = DoctorSchedule::query()
            ->whereHas('doctor', fn (Builder $query) => $query->active())
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $on))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $on))
            ->get(['weekday', 'starts_at', 'ends_at']);

        $week = [];

        foreach (array_keys(self::DAYS) as $day) {
            $ranges = $rows->where('weekday', $day)
                ->map(fn (DoctorSchedule $row) => [substr((string) $row->starts_at, 0, 5), substr((string) $row->ends_at, 0, 5)])
                ->sortBy(0)
                ->values()
                ->all();

            $week[$day] = self::merge($ranges);
        }

        return new self($week);
    }

    /** @return list<array{0: string, 1: string}> */
    public function forDay(int $weekday): array
    {
        return $this->week[$weekday] ?? [];
    }

    public function isEmpty(): bool
    {
        return collect($this->week)->flatten()->isEmpty();
    }

    /**
     * Días seguidos con el mismo horario, en una línea: "Lunes a viernes" → ["9:00 a. m. – 1:00 p. m.", "3:00 p. m. – 7:00 p. m."].
     *
     * @return list<array{days: string, hours: list<string>}>
     */
    public function lines(): array
    {
        $groups = [];

        foreach (array_keys(self::DAYS) as $day) {
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['ranges'] === $this->forDay($day)) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'ranges' => $this->forDay($day)];
            }
        }

        return array_map(fn (array $group) => [
            'days' => ucfirst(match ($group['to'] - $group['from']) {
                0 => self::DAYS[$group['from']],
                1 => self::DAYS[$group['from']].' y '.self::DAYS[$group['to']],
                default => self::DAYS[$group['from']].' a '.self::DAYS[$group['to']],
            }),
            'hours' => $group['ranges'] === []
                ? ['Cerrado']
                : array_map(fn (array $r) => Dates::time($r[0]).' – '.Dates::time($r[1]), $group['ranges']),
        ], $groups);
    }

    public function isOpen(CarbonImmutable $now): bool
    {
        $time = $now->format('H:i');

        return collect($this->forDay($now->dayOfWeekIso))->contains(fn (array $r) => $time >= $r[0] && $time < $r[1]);
    }

    /** "Abierto ahora · cierra a la 1:00 p. m." o "Cerrado ahora · abre hoy a las 3:00 p. m.". */
    public function status(CarbonImmutable $now): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $time = $now->format('H:i');

        foreach ($this->forDay($now->dayOfWeekIso) as [$start, $end]) {
            if ($time >= $start && $time < $end) {
                return 'Abierto ahora · cierra '.self::at($end);
            }

            if ($time < $start) {
                return 'Cerrado ahora · abre hoy '.self::at($start);
            }
        }

        for ($i = 1; $i <= 7; $i++) {
            $day = $now->addDays($i);

            if ($ranges = $this->forDay($day->dayOfWeekIso)) {
                return 'Cerrado ahora · abre '.($i === 1 ? 'mañana' : 'el '.self::DAYS[$day->dayOfWeekIso]).' '.self::at($ranges[0][0]);
            }
        }

        return null;
    }

    /** Para los datos estructurados de Google (schema.org OpeningHoursSpecification). */
    public function specification(): array
    {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $days = [];

        // Un bloque por franja, con todos los días que la comparten.
        foreach ($this->week as $day => $ranges) {
            foreach ($ranges as [$start, $end]) {
                $days["{$start}-{$end}"][] = $names[$day];
            }
        }

        return collect($days)->map(fn (array $dayNames, string $range) => [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => $dayNames,
            ...array_combine(['opens', 'closes'], explode('-', $range)),
        ])->values()->all();
    }

    /** "a la 1:00 p. m." / "a las 9:00 a. m.". */
    private static function at(string $time): string
    {
        return (str_starts_with($time, '13:') || str_starts_with($time, '01:') ? 'a la ' : 'a las ').Dates::time($time);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $ranges  Ordenados por inicio.
     * @return list<array{0: string, 1: string}>
     */
    private static function merge(array $ranges): array
    {
        $merged = [];

        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }
}
