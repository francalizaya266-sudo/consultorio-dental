<?php

namespace App\Services\Agenda;

use App\Enums\DayPeriod;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\WaitlistEntry;
use App\Services\Booking\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que la recepción necesita para contestar "¿tienen hora?" sin recorrer la agenda: qué tan lleno está
 * cada día del mes, las próximas horas libres para un tratamiento y quién de la lista de espera cabe en un día.
 */
final class GapFinder
{
    /** Días hacia adelante en los que se buscan horas libres. */
    public const SEARCH_DAYS = 21;

    /** Entre dos horas ofrecidas el mismo día, al menos esto: así se ven opciones distintas y no 9:00, 9:15, 9:30. */
    private const SPREAD_MINUTES = 60;

    /** Personas de la lista de espera que se revisan por día (cada una pide buscar sus horarios). */
    private const WAITLIST_CHECKED = 8;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly WaitlistService $waitlist,
    ) {}

    /**
     * Qué parte del horario de atención ya tiene citas, día por día. Los bloqueos achican el horario
     * (unas vacaciones dejan el día cerrado, no lleno). Null si ese día no atiende nadie.
     * Son tres consultas para todo el rango: el mini calendario lo pide en cada refresco de la agenda.
     *
     * @param  Collection<int, Doctor>  $doctors
     * @return array<string, int|null> Porcentaje ocupado por fecha (Y-m-d).
     */
    public function occupancy(Collection $doctors, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $doctors->map(fn (Doctor $doctor) => $doctor->getKey())->all();
        $from = $from->startOfDay();
        $to = $to->endOfDay();

        $schedules = DoctorSchedule::query()->whereIn('doctor_id', $ids)->get()->groupBy('doctor_id');

        $appointments = Appointment::query()
            ->whereIn('doctor_id', $ids)
            ->occupying()
            ->whereBetween('starts_at', [$from, $to])
            ->get()
            ->groupBy(fn (Appointment $appointment) => $appointment->doctor_id.'|'.$appointment->starts_at->toDateString());

        $blocks = ScheduleBlock::query()
            ->where(fn (Builder $query) => $query->whereNull('doctor_id')->orWhereIn('doctor_id', $ids))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get();

        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $open = 0;
            $busy = 0;

            foreach ($ids as $id) {
                $ranges = $this->rangesOn($schedules->get($id, collect()), $day);

                if ($ranges === []) {
                    continue;
                }

                $blocked = $blocks
                    ->filter(fn (ScheduleBlock $block) => $block->doctor_id === null || $block->doctor_id === $id)
                    ->sum(fn (ScheduleBlock $block) => $this->overlap($block->starts_at, $block->ends_at, $ranges));

                $booked = collect($appointments->get($id.'|'.$day->toDateString(), []))
                    ->sum(fn (Appointment $appointment) => $this->overlap($appointment->starts_at, $appointment->occupied_until, $ranges));

                $hours = max(0, $this->minutes($ranges) - (int) $blocked);
                $open += $hours;
                $busy += min($hours, (int) $booked);
            }

            $days[$day->toDateString()] = $open === 0 ? null : (int) min(100, round($busy / $open * 100));
        }

        return $days;
    }

    /**
     * Las próximas horas libres para el tratamiento desde ese día: hasta $days días con lugar y unas pocas
     * horas por día, una de la mañana y una de la tarde primero, y las que no dejan huecos antes que las demás.
     *
     * @param  Collection<int, Doctor>  $doctors  Con sus tratamientos cargados.
     * @return list<array{date: CarbonImmutable, slots: list<array{time: string, doctor: Doctor, recommended: bool}>}>
     */
    public function nextSlots(Treatment $treatment, Collection $doctors, CarbonImmutable $from, int $days = 3, int $perDay = 4): array
    {
        $doctors = $doctors->filter(fn (Doctor $doctor) => $doctor->treatments->contains($treatment->getKey()))->values();
        $date = $from->max(CarbonImmutable::today())->startOfDay();
        $found = [];

        for ($checked = 0; $checked < self::SEARCH_DAYS && count($found) < $days && $doctors->isNotEmpty(); $checked++, $date = $date->addDay()) {
            $candidates = $doctors->flatMap(fn (Doctor $doctor) => $this->availability
                ->slotsFor($doctor, $treatment, $date, earliest: $date->isToday() ? now() : null)
                ->map(fn (Slot $slot) => ['slot' => $slot, 'doctor' => $doctor]));

            $picked = $this->spread($candidates, $perDay);

            if ($picked !== []) {
                $found[] = ['date' => $date, 'slots' => $picked];
            }
        }

        return $found;
    }

    /**
     * De la lista de espera, quienes caben ese día: les sirve el día y la parte del día que pidieron, y su
     * tratamiento entra con alguno de los doctores. Cada uno con la primera hora libre que le sirve.
     *
     * @param  Collection<int, Doctor>  $doctors  Con sus tratamientos cargados.
     * @return list<array{entry: WaitlistEntry, doctor: Doctor, time: string}>
     */
    public function waitlistFits(Collection $doctors, CarbonImmutable $date, int $limit = 3): array
    {
        if ($date->lt(CarbonImmutable::today()) || $doctors->isEmpty()) {
            return [];
        }

        $entries = WaitlistEntry::query()
            ->availableOn($date)
            ->whereNotNull('treatment_id')
            ->whereHas('patient')
            ->where(fn (Builder $query) => $query->whereNull('doctor_id')->orWhereIn('doctor_id', $doctors->map(fn (Doctor $doctor) => $doctor->getKey())->all()))
            ->with(['patient.account', 'treatment'])
            ->oldest()
            ->limit(self::WAITLIST_CHECKED)
            ->get();

        $fits = [];

        foreach ($entries as $entry) {
            foreach ($doctors as $doctor) {
                if (($entry->doctor_id !== null && $entry->doctor_id !== $doctor->getKey()) || ! $doctor->treatments->contains($entry->treatment_id)) {
                    continue;
                }

                $slot = $this->waitlist->slotsFor($entry, $doctor, $entry->treatment, $date)->first();

                if ($slot) {
                    $fits[] = ['entry' => $entry, 'doctor' => $doctor, 'time' => $slot->label()];

                    break;
                }
            }

            if (count($fits) === $limit) {
                break;
            }
        }

        return $fits;
    }

    /**
     * @param  Collection<int, array{slot: Slot, doctor: Doctor}>  $candidates
     * @return list<array{time: string, doctor: Doctor, recommended: bool}>
     */
    private function spread(Collection $candidates, int $limit): array
    {
        $ranked = $candidates->sortBy([
            fn (array $a, array $b) => $b['slot']->recommended <=> $a['slot']->recommended,
            fn (array $a, array $b) => $a['slot']->wasteMinutes <=> $b['slot']->wasteMinutes,
            fn (array $a, array $b) => $a['slot']->startsAt <=> $b['slot']->startsAt,
        ])->values();

        $isMorning = fn (array $candidate) => $candidate['slot']->startsAt->format('H:i') < DayPeriod::AFTERNOON_FROM;

        // La mejor de la mañana y la mejor de la tarde van primero; después, las que siguen sin quedar muy juntas.
        $picked = collect([$ranked->first($isMorning), $ranked->first(fn (array $candidate) => ! $isMorning($candidate))])->filter()->take($limit);

        foreach ($ranked as $candidate) {
            if ($picked->count() >= $limit) {
                break;
            }

            $tooClose = $picked->contains(fn (array $chosen) => abs($chosen['slot']->startsAt->diffInMinutes($candidate['slot']->startsAt, false)) < self::SPREAD_MINUTES);

            if (! $tooClose) {
                $picked->push($candidate);
            }
        }

        return $picked
            ->sortBy(fn (array $candidate) => $candidate['slot']->startsAt->getTimestamp())
            ->map(fn (array $candidate) => ['time' => $candidate['slot']->label(), 'doctor' => $candidate['doctor'], 'recommended' => $candidate['slot']->recommended])
            ->values()
            ->all();
    }

    /**
     * Tramos de atención del doctor ese día, de los horarios ya cargados (como AvailabilityService::workingRanges, sin consultar).
     *
     * @param  Collection<int, DoctorSchedule>  $schedules
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function rangesOn(Collection $schedules, CarbonImmutable $day): array
    {
        return $schedules
            ->filter(fn (DoctorSchedule $schedule) => $schedule->weekday === $day->dayOfWeekIso
                && ($schedule->valid_from === null || $schedule->valid_from->lte($day))
                && ($schedule->valid_until === null || $schedule->valid_until->gte($day)))
            ->map(fn (DoctorSchedule $schedule) => [$day->setTimeFromTimeString($schedule->starts_at), $day->setTimeFromTimeString($schedule->ends_at)])
            ->values()
            ->all();
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges */
    private function overlap(\DateTimeInterface $start, \DateTimeInterface $end, array $ranges): int
    {
        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);

        return (int) array_sum(array_map(
            fn (array $range) => max(0, (int) $start->max($range[0])->diffInMinutes($end->min($range[1]), false)),
            $ranges,
        ));
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges */
    private function minutes(array $ranges): int
    {
        return (int) array_sum(array_map(fn (array $range) => $range[0]->diffInMinutes($range[1]), $ranges));
    }
}
