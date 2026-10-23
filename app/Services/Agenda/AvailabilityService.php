<?php

namespace App\Services\Agenda;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\ScheduleBlock;
use App\Models\Setting;
use App\Models\Treatment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Agenda inteligente: calcula los horarios donde cabe completa la duración
 * del tratamiento más el tiempo de esterilización, y marca primero los que
 * quedan pegados a citas existentes para no dejar huecos.
 */
class AvailabilityService
{
    /**
     * @param  CarbonInterface|null  $earliest  No ofrecer horarios antes de este momento (anticipación mínima).
     * @param  int|null  $ignoreAppointmentId  Cita que se está moviendo (no cuenta como ocupada).
     * @return Collection<int, Slot>
     */
    public function slotsFor(
        Doctor $doctor,
        Treatment $treatment,
        CarbonInterface $date,
        ?CarbonInterface $earliest = null,
        ?int $ignoreAppointmentId = null,
    ): Collection {
        $duration = $doctor->durationFor($treatment);
        $buffer = $treatment->bufferMinutes();
        $step = max(5, (int) Setting::get('agenda.slot_step_minutes', 15));
        $minUsefulGap = (int) Setting::get('agenda.min_useful_gap_minutes', 30);
        $earliest = $earliest ? CarbonImmutable::instance($earliest) : null;

        $slots = collect();

        foreach ($this->freeWindows($doctor, CarbonImmutable::instance($date), $ignoreAppointmentId) as $window) {
            // Si después viene otra cita, la nuestra debe terminar con su esterilización antes;
            // si viene el cierre o un bloqueo, basta con que termine la atención.
            $needed = $duration + ($window['end_is_appointment'] ? $buffer : 0);
            $latest = $this->floorTo($window['end']->subMinutes($needed), 5);
            $first = $window['start'];

            if ($earliest && $first->lt($earliest)) {
                $first = $this->ceilTo($earliest, $step);
            }

            if ($first->gt($latest)) {
                continue;
            }

            $candidates = [$first->getTimestamp() => $first, $latest->getTimestamp() => $latest];

            for ($t = $this->ceilTo($first, $step); $t->lte($latest); $t = $t->addMinutes($step)) {
                $candidates[$t->getTimestamp()] = $t;
            }

            foreach ($candidates as $start) {
                $gapBefore = (int) $window['start']->diffInMinutes($start);
                $gapAfter = (int) $start->addMinutes($needed)->diffInMinutes($window['end']);

                $waste = collect([$gapBefore, $gapAfter])
                    ->filter(fn (int $gap) => $gap > 0 && $gap < $minUsefulGap)
                    ->sum();

                $slots->push(new Slot(
                    startsAt: $start,
                    endsAt: $start->addMinutes($duration),
                    recommended: ($gapBefore === 0 && $window['start_is_appointment'])
                        || ($gapAfter === 0 && $window['end_is_appointment']),
                    wasteMinutes: $waste,
                ));
            }
        }

        return $slots->sortBy(fn (Slot $slot) => $slot->startsAt->getTimestamp())->values();
    }

    /** ¿Cabe una cita en este horario sin cruzarse con otra ni con un bloqueo? */
    public function isFree(
        Doctor $doctor,
        CarbonInterface $startsAt,
        int $durationMinutes,
        int $bufferMinutes,
        ?int $ignoreAppointmentId = null,
        bool $requireWorkingHours = true,
    ): bool {
        $start = CarbonImmutable::instance($startsAt);
        $end = $start->addMinutes($durationMinutes);
        $occupiedUntil = $end->addMinutes($bufferMinutes);

        $clashesWithAppointment = $this->occupyingAppointments($doctor, $start->subDay(), $occupiedUntil, $ignoreAppointmentId)
            ->contains(fn (Appointment $a) => $a->starts_at->lt($occupiedUntil) && $a->occupied_until->gt($start));

        if ($clashesWithAppointment) {
            return false;
        }

        if (ScheduleBlock::query()->affecting($doctor, $start, $end)->exists()) {
            return false;
        }

        if (! $requireWorkingHours) {
            return true;
        }

        return $this->workingRanges($doctor, $start)
            ->contains(fn (array $range) => $start->gte($range['start']) && $end->lte($range['end']));
    }

    /**
     * Ventanas libres del día: horario de atención menos citas y bloqueos.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, start_is_appointment: bool, end_is_appointment: bool}>
     */
    protected function freeWindows(Doctor $doctor, CarbonImmutable $date, ?int $ignoreAppointmentId): array
    {
        $ranges = $this->workingRanges($doctor, $date);

        if ($ranges->isEmpty()) {
            return [];
        }

        $dayStart = $date->startOfDay();
        $dayEnd = $date->endOfDay();

        $busy = $this->occupyingAppointments($doctor, $dayStart->subDay(), $dayEnd, $ignoreAppointmentId)
            ->toBase()
            ->map(fn (Appointment $a) => [
                'start' => CarbonImmutable::instance($a->starts_at),
                'end' => CarbonImmutable::instance($a->occupied_until),
                'is_appointment' => true,
            ])
            ->merge(ScheduleBlock::query()->affecting($doctor, $dayStart, $dayEnd)->get()->toBase()->map(fn (ScheduleBlock $b) => [
                'start' => CarbonImmutable::instance($b->starts_at),
                'end' => CarbonImmutable::instance($b->ends_at),
                'is_appointment' => false,
            ]))
            ->filter(fn (array $b) => $b['end']->gt($dayStart) && $b['start']->lt($dayEnd))
            ->sortBy(fn (array $b) => $b['start']->getTimestamp())
            ->values();

        $windows = [];

        foreach ($ranges as $range) {
            $cursor = $range['start'];
            $cursorIsAppointment = false;

            foreach ($busy as $b) {
                if ($b['end']->lte($cursor) || $b['start']->gte($range['end'])) {
                    continue;
                }

                if ($b['start']->gt($cursor)) {
                    $windows[] = [
                        'start' => $cursor,
                        'end' => $b['start'],
                        'start_is_appointment' => $cursorIsAppointment,
                        'end_is_appointment' => $b['is_appointment'],
                    ];
                }

                if ($b['end']->gt($cursor)) {
                    $cursor = $b['end'];
                    $cursorIsAppointment = $b['is_appointment'];
                }
            }

            if ($cursor->lt($range['end'])) {
                $windows[] = [
                    'start' => $cursor,
                    'end' => $range['end'],
                    'start_is_appointment' => $cursorIsAppointment,
                    'end_is_appointment' => false,
                ];
            }
        }

        return $windows;
    }

    /** @return Collection<int, array{start: CarbonImmutable, end: CarbonImmutable}> */
    protected function workingRanges(Doctor $doctor, CarbonInterface $date): Collection
    {
        $day = CarbonImmutable::instance($date)->startOfDay();

        return DoctorSchedule::query()
            ->whereBelongsTo($doctor)
            ->validOn($day)
            ->orderBy('starts_at')
            ->get()
            ->toBase()
            ->map(fn (DoctorSchedule $s) => [
                'start' => $day->setTimeFromTimeString($s->starts_at),
                'end' => $day->setTimeFromTimeString($s->ends_at),
            ]);
    }

    /** @return Collection<int, Appointment> */
    protected function occupyingAppointments(Doctor $doctor, CarbonInterface $from, CarbonInterface $to, ?int $ignoreId): Collection
    {
        return Appointment::query()
            ->whereBelongsTo($doctor)
            ->occupying()
            ->where('starts_at', '<', $to)
            ->where('starts_at', '>=', $from)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();
    }

    private function ceilTo(CarbonImmutable $time, int $minutes): CarbonImmutable
    {
        $time = $time->setSecond(0)->setMicrosecond(0);
        $remainder = $time->minute % $minutes;

        return $remainder === 0 ? $time : $time->addMinutes($minutes - $remainder);
    }

    private function floorTo(CarbonImmutable $time, int $minutes): CarbonImmutable
    {
        $time = $time->setSecond(0)->setMicrosecond(0);

        return $time->subMinutes($time->minute % $minutes);
    }
}
