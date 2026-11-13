<?php

namespace App\Services\Agenda;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\ScheduleBlock;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Agenda inteligente: calcula los horarios donde cabe completa la duración
 * del tratamiento más el tiempo de esterilización, y marca primero los que
 * quedan pegados a citas existentes para no dejar huecos. Un horario ofrecido
 * a alguien de la lista de espera cuenta como ocupado mientras está apartado.
 */
class AvailabilityService
{
    /** Paciente para quien se mira la agenda: lo que tiene apartado de la lista de espera le sirve. */
    private ?int $holderPatientId = null;

    /** La misma agenda vista para este paciente: su horario apartado no cuenta como ocupado. */
    public function forPatient(?int $patientId): static
    {
        $clone = clone $this;
        $clone->holderPatientId = $patientId;

        return $clone;
    }

    /**
     * @param  CarbonInterface|null  $earliest  No ofrecer horarios antes de este momento (anticipación mínima).
     * @param  int|null  $ignoreAppointmentId  Cita que se está moviendo (no cuenta como ocupada).
     * @param  int|null  $durationMinutes  Duración puesta a mano para esta cita; null, lo que ese doctor tarda en el tratamiento.
     * @return Collection<int, Slot>
     */
    public function slotsFor(
        Doctor $doctor,
        Treatment $treatment,
        CarbonInterface $date,
        ?CarbonInterface $earliest = null,
        ?int $ignoreAppointmentId = null,
        ?int $durationMinutes = null,
    ): Collection {
        $duration = $durationMinutes ?? $doctor->durationFor($treatment);
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

        if ($this->clashAt($doctor, $start, $durationMinutes, $bufferMinutes, $ignoreAppointmentId) !== null
            || $this->holdAt($doctor, $start, $durationMinutes, $bufferMinutes) !== null) {
            return false;
        }

        if (! $requireWorkingHours) {
            return true;
        }

        return $this->workingRanges($doctor, $start)
            ->contains(fn (array $range) => $start->gte($range['start']) && $end->lte($range['end']));
    }

    /**
     * Con qué se cruza una cita puesta en ese horario: la primera cita que ocupa ese tramo (contando su tiempo de
     * esterilización), con su paciente ya cargado para poder nombrarlo, o, si no hay, el bloqueo de la agenda. Null si
     * no se cruza con ninguno.
     */
    public function clashAt(
        Doctor $doctor,
        CarbonInterface $startsAt,
        int $durationMinutes,
        int $bufferMinutes,
        ?int $ignoreAppointmentId = null,
    ): Appointment|ScheduleBlock|null {
        $start = CarbonImmutable::instance($startsAt);
        $end = $start->addMinutes($durationMinutes);
        $occupiedUntil = $end->addMinutes($bufferMinutes);

        return $this->occupyingAppointments($doctor, $start->subDay(), $occupiedUntil, $ignoreAppointmentId)
            ->sortBy('starts_at')
            ->first(fn (Appointment $a) => $a->starts_at->lt($occupiedUntil) && $a->occupied_until->gt($start))
            ?->loadMissing('patient')
            ?? $this->blockAt($doctor, $start, $durationMinutes);
    }

    /** El bloqueo de la agenda (del doctor o de todo el consultorio) que se cruza con una cita puesta en ese horario. */
    public function blockAt(Doctor $doctor, CarbonInterface $startsAt, int $durationMinutes): ?ScheduleBlock
    {
        $start = CarbonImmutable::instance($startsAt);

        return ScheduleBlock::query()->affecting($doctor, $start, $start->addMinutes($durationMinutes))->orderBy('starts_at')->first();
    }

    /**
     * Con qué se cruzaría una cita ya agendada si pasara a terminar a otra hora (dura más, o su tratamiento pide más
     * esterilización): la cita, el bloqueo o el horario apartado que caen en el tiempo que se agrega. Lo que la cita
     * ya ocupaba no cuenta. Null si no se cruza con nada.
     */
    public function clashWhenExtending(Appointment $appointment, CarbonInterface $endsAt, int $bufferMinutes): Appointment|ScheduleBlock|WaitlistEntry|null
    {
        $doctor = $appointment->doctor;
        $oldEnd = CarbonImmutable::instance($appointment->ends_at);
        $oldUntil = CarbonImmutable::instance($appointment->occupied_until);
        $newEnd = CarbonImmutable::instance($endsAt);
        $newUntil = $newEnd->addMinutes($bufferMinutes);

        if ($newUntil->gt($oldUntil)) {
            $next = $this->occupyingAppointments($doctor, $oldUntil->subDay(), $newUntil, $appointment->getKey())
                ->sortBy('starts_at')
                ->first(fn (Appointment $a) => $a->starts_at->lt($newUntil) && $a->occupied_until->gt($oldUntil))
                ?->loadMissing('patient')
                ?? $this->forPatient($appointment->patient_id)->holds($doctor, $oldUntil, $newUntil)->first();

            if ($next !== null) {
                return $next;
            }
        }

        return $newEnd->gt($oldEnd)
            ? ScheduleBlock::query()->affecting($doctor, $oldEnd, $newEnd)->orderBy('starts_at')->first()
            : null;
    }

    /** Quién tiene apartado (por la lista de espera) un horario que se cruza con este. Null si nadie. */
    public function holdAt(Doctor $doctor, CarbonInterface $startsAt, int $durationMinutes, int $bufferMinutes): ?WaitlistEntry
    {
        $start = CarbonImmutable::instance($startsAt);

        return $this->holds($doctor, $start, $start->addMinutes($durationMinutes + $bufferMinutes))->first();
    }

    /**
     * Horarios apartados para alguien de la lista de espera que caen en ese tramo (sin los del paciente
     * para quien se mira la agenda).
     *
     * @return Collection<int, WaitlistEntry>
     */
    public function holds(Doctor $doctor, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return WaitlistEntry::query()
            ->holding()
            ->where('offered_doctor_id', $doctor->getKey())
            ->where('offered_starts_at', '<', $to)
            ->where('offered_until', '>', $from)
            ->when($this->holderPatientId, fn ($query, int $id) => $query->where('patient_id', '!=', $id))
            ->with('patient')
            ->orderBy('offered_starts_at')
            ->get();
    }

    /**
     * Cómo está el día del doctor: minutos de atención, cuántos quedan libres y los huecos
     * donde aún entra una cita (los de al menos "hueco útil" minutos, desde $from si es hoy).
     *
     * @return array{open: int, free: int, gaps: list<array{start: CarbonImmutable, end: CarbonImmutable}>}
     */
    public function dayGaps(Doctor $doctor, CarbonInterface $date, ?CarbonInterface $from = null): array
    {
        $date = CarbonImmutable::instance($date);
        $from = $from ? $this->ceilTo(CarbonImmutable::instance($from), 5) : null;
        $minUsefulGap = (int) Setting::get('agenda.min_useful_gap_minutes', 30);
        $windows = $this->freeWindows($doctor, $date, null);
        $gaps = [];

        foreach ($windows as $window) {
            $start = $from ? $window['start']->max($from) : $window['start'];

            if ($start->diffInMinutes($window['end'], false) >= $minUsefulGap) {
                $gaps[] = ['start' => $start, 'end' => $window['end']];
            }
        }

        return [
            'open' => (int) $this->workingRanges($doctor, $date)->sum(fn (array $range) => $range['start']->diffInMinutes($range['end'])),
            'free' => (int) collect($windows)->sum(fn (array $window) => $window['start']->diffInMinutes($window['end'])),
            'gaps' => $gaps,
        ];
    }

    /**
     * Ventanas libres del día: horario de atención menos citas, bloqueos y horarios apartados.
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
            ->merge($this->holds($doctor, $dayStart, $dayEnd)->toBase()->map(fn (WaitlistEntry $hold) => [
                'start' => CarbonImmutable::instance($hold->offered_starts_at),
                'end' => CarbonImmutable::instance($hold->offered_until),
                'is_appointment' => true,
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

    /**
     * Tramos de atención del doctor ese día, según su horario semanal.
     *
     * @return Collection<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function workingRanges(Doctor $doctor, CarbonInterface $date): Collection
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
