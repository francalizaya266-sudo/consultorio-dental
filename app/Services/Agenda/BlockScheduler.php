<?php

namespace App\Services\Agenda;

use App\Enums\ScheduleBlockType;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Support\PeruvianHolidays;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Crea los bloqueos de la agenda que no son uno suelto: el que se repite cada semana (el curso de los jueves por la
 * tarde) y los feriados del año, que quedan como días completos para todo el consultorio.
 */
final class BlockScheduler
{
    /** Hasta cuántas semanas seguidas se repite un bloqueo. */
    public const REPEAT_MAX_WEEKS = 26;

    /**
     * El bloqueo y, si se repite, el mismo horario en cada una de las semanas siguientes. Cada uno queda como un
     * bloqueo aparte: se corrige o se borra solo.
     *
     * @param  array{doctor_id?: int|null, type: ScheduleBlockType|string, starts_at: CarbonInterface|string, ends_at: CarbonInterface|string, reason?: string|null}  $attributes
     * @param  int  $weeks  En cuántas semanas seguidas, contando la primera.
     * @return Collection<int, ScheduleBlock>
     */
    public function create(User $by, array $attributes, int $weeks = 1): Collection
    {
        $startsAt = CarbonImmutable::parse($attributes['starts_at']);
        $endsAt = CarbonImmutable::parse($attributes['ends_at']);

        return DB::transaction(fn () => collect(range(0, min(max($weeks, 1), self::REPEAT_MAX_WEEKS) - 1))
            ->map(fn (int $week) => ScheduleBlock::create([
                'doctor_id' => $attributes['doctor_id'] ?? null,
                'type' => $attributes['type'],
                'starts_at' => $startsAt->addWeeks($week),
                'ends_at' => $endsAt->addWeeks($week),
                'reason' => $attributes['reason'] ?? null,
                'created_by_user_id' => $by->getKey(),
            ])));
    }

    /**
     * Los feriados de ese año que todavía se pueden cargar: los que no pasaron y que el consultorio aún no tiene
     * bloqueados de día completo.
     *
     * @return array<string, string> Fecha ("2027-01-01") => nombre.
     */
    public function pendingHolidays(int $year): array
    {
        return collect(PeruvianHolidays::of($year))
            ->filter(fn (string $name, string $date) => $date >= today()->toDateString() && ! $this->isClosedAllDay(CarbonImmutable::parse($date)))
            ->all();
    }

    /**
     * Bloquea esos feriados como días completos para todo el consultorio. Las fechas que no son feriado de su año, las
     * que ya pasaron o las que ya estaban bloqueadas no se tocan.
     *
     * @param  list<string>  $dates  Fechas ("2027-01-01").
     * @return Collection<int, ScheduleBlock>
     */
    public function loadHolidays(User $by, array $dates): Collection
    {
        return DB::transaction(fn () => collect($dates)
            ->unique()
            ->sort()
            ->map(fn (string $date) => CarbonImmutable::parse($date)->startOfDay())
            ->filter(fn (CarbonImmutable $day) => array_key_exists($day->toDateString(), $this->pendingHolidays($day->year)))
            ->map(fn (CarbonImmutable $day) => ScheduleBlock::create([
                'doctor_id' => null,
                'type' => ScheduleBlockType::Holiday,
                'starts_at' => $day,
                'ends_at' => $day->addDay(),
                'reason' => PeruvianHolidays::of($day->year)[$day->toDateString()],
                'created_by_user_id' => $by->getKey(),
            ]))
            ->values());
    }

    /** ¿Todo el consultorio ya tiene ese día bloqueado completo? */
    private function isClosedAllDay(CarbonImmutable $day): bool
    {
        return ScheduleBlock::query()
            ->whereNull('doctor_id')
            ->where('starts_at', '<=', $day->startOfDay())
            ->where('ends_at', '>=', $day->setTime(23, 55))
            ->exists();
    }
}
