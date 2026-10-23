<?php

namespace App\Services\Reports;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Payment;
use App\Models\ScheduleBlock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Cómo se movió el consultorio en un período: citas, pacientes nuevos, inasistencias, horarios y ocupación. */
final class ActivityReport
{
    public const NO_ORIGIN = 'Sin dato';

    private const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public function __construct(
        public readonly ReportPeriod $period,
        public readonly ?int $doctorId = null,
    ) {}

    /** @return Builder<Appointment> */
    private function appointments(?ReportPeriod $period = null): Builder
    {
        $period ??= $this->period;

        return Appointment::query()
            ->whereBetween('starts_at', [$period->from, $period->to])
            ->when($this->doctorId, fn (Builder $query, int $id) => $query->where('doctor_id', $id));
    }

    public function attended(): int
    {
        return $this->appointments()->where('status', AppointmentStatus::Completed)->count();
    }

    public function noShows(): int
    {
        return $this->appointments()->where('status', AppointmentStatus::NoShow)->count();
    }

    /** Porcentaje de citas que debieron ocurrir y el paciente no vino. Null si no hubo ninguna. */
    public function noShowRate(): ?float
    {
        $due = $this->attended() + $this->noShows();

        return $due > 0 ? round($this->noShows() / $due * 100, 1) : null;
    }

    /** Pacientes cuya primera visita al consultorio cayó en el período. */
    public function newPatients(): int
    {
        return $this->visits($this->period)->filter(fn (array $visit) => $visit['is_first'])->pluck('patient_id')->unique()->count();
    }

    /**
     * Cobrado mes a mes, terminando en el mes actual.
     *
     * @return array<string, float> Etiqueta del mes => monto
     */
    public function incomeByMonth(int $months = 12): array
    {
        $period = ReportPeriod::months($months);
        $byDay = Payment::query()
            ->whereNull('voided_at')
            ->whereBetween('paid_at', [$period->from, $period->to])
            ->when($this->doctorId, fn (Builder $query, int $id) => $query->where('doctor_id', $id))
            ->selectRaw('date(paid_at) as day, sum(amount) as total')
            ->groupByRaw('date(paid_at)')
            ->toBase()
            ->pluck('total', 'day');

        return $this->monthBuckets($period, fn (CarbonImmutable $month) => round((float) $byDay
            ->filter(fn ($total, string $day) => CarbonImmutable::parse($day)->isSameMonth($month))
            ->sum(), 2));
    }

    /**
     * Citas que empiezan en cada hora del día (las reservadas, se hayan atendido o no).
     *
     * @return array<int, int> Hora (0–23) => citas, solo las horas con citas y las que quedan entre ellas
     */
    public function busiestHours(): array
    {
        $hours = $this->appointments()
            ->whereIn('status', [...AppointmentStatus::occupying(), AppointmentStatus::NoShow])
            ->pluck('starts_at')
            ->countBy(fn ($startsAt) => CarbonImmutable::parse($startsAt)->hour);

        if ($hours->isEmpty()) {
            return [];
        }

        return collect(range($hours->keys()->min(), $hours->keys()->max()))
            ->mapWithKeys(fn (int $hour) => [$hour => $hours[$hour] ?? 0])
            ->all();
    }

    /**
     * Pacientes atendidos cada mes: los que venían por primera vez y los que ya eran pacientes.
     *
     * @return array<string, array{new: int, returning: int}>
     */
    public function newVsReturning(int $months = 6): array
    {
        $period = ReportPeriod::months($months);
        $visits = $this->visits($period);

        return $this->monthBuckets($period, function (CarbonImmutable $month) use ($visits) {
            $inMonth = $visits->filter(fn (array $visit) => $visit['starts_at']->isSameMonth($month));
            $new = $inMonth->filter(fn (array $visit) => $visit['is_first'])->pluck('patient_id')->unique();

            return [
                'new' => $new->count(),
                'returning' => $inMonth->pluck('patient_id')->unique()->diff($new)->count(),
            ];
        });
    }

    /**
     * De dónde vienen las citas del período (lo que dijo el paciente al reservar o lo que tiene en su ficha).
     *
     * @return array<string, int> Origen => citas, de más a menos y "Sin dato" al final
     */
    public function origins(): array
    {
        $counts = $this->appointments()
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->with('patient:id,origin')
            ->get(['id', 'patient_id', 'origin'])
            ->countBy(fn (Appointment $appointment) => ($appointment->origin ?? $appointment->patient?->origin)?->getLabel() ?? self::NO_ORIGIN)
            ->sortDesc();

        return $counts->except(self::NO_ORIGIN)
            ->when($counts->has(self::NO_ORIGIN), fn (Collection $c) => $c->put(self::NO_ORIGIN, $counts[self::NO_ORIGIN]))
            ->all();
    }

    /**
     * Qué parte del horario de atención de cada doctor estuvo reservada (sin contar los bloqueos).
     *
     * @return array<string, int> Doctor => porcentaje (0–100)
     */
    public function occupancyByDoctor(): array
    {
        return $this->occupancyDoctors()
            ->mapWithKeys(function (Doctor $doctor) {
                $available = array_sum($this->workingMinutes($doctor));

                return $available > 0
                    ? [$doctor->name => self::share($this->bookedAppointments($doctor)->sum(fn (Appointment $a) => $a->starts_at->diffInMinutes($a->ends_at)), $available)]
                    : [];
            })
            ->all();
    }

    /**
     * Lo mismo, día por día de la semana: con un solo doctor muestra qué días tienen espacio.
     *
     * @return array<string, int> "Lunes", "Martes"… (solo los días con horario) => porcentaje (0–100)
     */
    public function occupancyByWeekday(): array
    {
        $available = array_fill(1, 7, 0);
        $booked = array_fill(1, 7, 0);

        foreach ($this->occupancyDoctors() as $doctor) {
            foreach ($this->workingMinutes($doctor) as $weekday => $minutes) {
                $available[$weekday] += $minutes;
            }

            foreach ($this->bookedAppointments($doctor) as $appointment) {
                $booked[$appointment->starts_at->dayOfWeekIso] += $appointment->starts_at->diffInMinutes($appointment->ends_at);
            }
        }

        return collect($available)
            ->filter(fn (int $minutes) => $minutes > 0)
            ->mapWithKeys(fn (int $minutes, int $weekday) => [self::WEEKDAYS[$weekday] => self::share($booked[$weekday], $minutes)])
            ->all();
    }

    /** @return Collection<int, Doctor> Los doctores activos que entran en el reporte. */
    private function occupancyDoctors(): Collection
    {
        return Doctor::query()
            ->active()
            ->when($this->doctorId, fn (Builder $query, int $id) => $query->whereKey($id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Appointment> Las citas que ocuparon el horario del doctor en el período, aunque el paciente no viniera. */
    private function bookedAppointments(Doctor $doctor): Collection
    {
        return Appointment::query()
            ->whereBelongsTo($doctor)
            ->whereIn('status', [...AppointmentStatus::occupying(), AppointmentStatus::NoShow])
            ->whereBetween('starts_at', [$this->period->from, $this->period->to])
            ->get(['starts_at', 'ends_at']);
    }

    private static function share(int|float $booked, int $available): int
    {
        return (int) min(100, round($booked / $available * 100));
    }

    /**
     * Porcentaje de inasistencias mes a mes. Null en los meses sin citas.
     *
     * @return array<string, ?float>
     */
    public function noShowRateByMonth(int $months = 12): array
    {
        $period = ReportPeriod::months($months);
        $appointments = $this->appointments($period)
            ->whereIn('status', [AppointmentStatus::Completed, AppointmentStatus::NoShow])
            ->get(['starts_at', 'status']);

        return $this->monthBuckets($period, function (CarbonImmutable $month) use ($appointments) {
            $inMonth = $appointments->filter(fn (Appointment $a) => $a->starts_at->isSameMonth($month));
            $missed = $inMonth->filter(fn (Appointment $a) => $a->status === AppointmentStatus::NoShow)->count();

            return $inMonth->isEmpty() ? null : round($missed / $inMonth->count() * 100, 1);
        });
    }

    /**
     * Citas atendidas del período y si fue la primera visita del paciente al consultorio.
     *
     * @return Collection<int, array{patient_id: int, starts_at: CarbonImmutable, is_first: bool}>
     */
    private function visits(ReportPeriod $period): Collection
    {
        return $this->appointments($period)
            ->where('status', AppointmentStatus::Completed)
            ->with('patient:id,first_visit_at')
            ->get(['id', 'patient_id', 'starts_at'])
            ->toBase()
            ->map(fn (Appointment $a) => [
                'patient_id' => $a->patient_id,
                'starts_at' => CarbonImmutable::instance($a->starts_at),
                // La primera visita queda guardada al terminar la primera cita; antes de eso, es nuevo.
                'is_first' => $a->patient?->first_visit_at === null || $a->patient->first_visit_at->gte($a->starts_at),
            ]);
    }

    /**
     * Minutos de atención del doctor en el período, según su horario semanal y menos los bloqueos.
     *
     * @return array<int, int> Día de la semana (1 = lunes) => minutos
     */
    private function workingMinutes(Doctor $doctor): array
    {
        $schedules = DoctorSchedule::query()->whereBelongsTo($doctor)->get();
        $blocks = ScheduleBlock::query()->affecting($doctor, $this->period->from, $this->period->to)->get();
        $minutes = array_fill(1, 7, 0);

        for ($day = $this->period->from; $day->lte($this->period->to); $day = $day->addDay()) {
            $ranges = $schedules->filter(fn (DoctorSchedule $s) => $s->weekday === $day->dayOfWeekIso
                && ($s->valid_from === null || $s->valid_from->lte($day))
                && ($s->valid_until === null || $s->valid_until->gte($day)));

            foreach ($ranges as $schedule) {
                $start = $day->setTimeFromTimeString($schedule->starts_at);
                $end = $day->setTimeFromTimeString($schedule->ends_at);
                $blocked = $blocks->sum(fn (ScheduleBlock $b) => max(0, CarbonImmutable::instance($b->starts_at)->max($start)
                    ->diffInMinutes(CarbonImmutable::instance($b->ends_at)->min($end), false)));

                $minutes[$day->dayOfWeekIso] += (int) max(0, $start->diffInMinutes($end) - $blocked);
            }
        }

        return $minutes;
    }

    /**
     * @template T
     *
     * @param  callable(CarbonImmutable): T  $value
     * @return array<string, T> "Ene", "Feb"… (con el año si no es el actual) => valor
     */
    private function monthBuckets(ReportPeriod $period, callable $value): array
    {
        $buckets = [];

        for ($month = $period->from->startOfMonth(); $month->lte($period->to); $month = $month->addMonthNoOverflow()) {
            $label = ucfirst(rtrim($month->translatedFormat('M'), '.')).($month->isCurrentYear() ? '' : ' '.$month->year);
            $buckets[$label] = $value($month);
        }

        return $buckets;
    }
}
