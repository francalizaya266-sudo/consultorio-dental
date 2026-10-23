<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Setting;
use App\Models\Treatment;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que la web ofrece para reservar o cambiar una cita: tratamientos, doctores, días y
 * horarios, respetando la anticipación mínima y el horizonte que define el consultorio.
 */
class WebAvailability
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /** @return Collection<int, Treatment> Tratamientos que el paciente reserva solo (con algún doctor que los atienda). */
    public function treatments(): Collection
    {
        return Treatment::query()
            ->onlineBookable()
            ->whereHas('doctors', fn (Builder $query) => $query->active()->where('accepts_online_booking', true))
            ->orderByDesc('is_evaluation')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Doctor> */
    public function doctorsFor(Treatment $treatment): Collection
    {
        return $treatment->doctors()
            ->active()
            ->where('accepts_online_booking', true)
            ->orderBy('sort_order')
            ->get();
    }

    /** Primer momento que se puede reservar desde la web. */
    public function earliest(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) Setting::get('booking.min_lead_minutes', 120));
    }

    public function lastDay(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays((int) Setting::get('booking.horizon_days', 45));
    }

    /**
     * Los próximos días con algún horario libre.
     *
     * @param  Collection<int, Doctor>  $doctors
     * @return list<CarbonImmutable>
     */
    public function days(Treatment $treatment, Collection $doctors, int $count = 12, ?Appointment $moving = null): array
    {
        $days = [];

        for ($day = $this->earliest()->startOfDay(); $day->lte($this->lastDay()) && count($days) < $count; $day = $day->addDay()) {
            if ($this->slots($treatment, $doctors, $day, $moving)->isNotEmpty()) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Horarios libres de un día. Con varios doctores, cada hora queda con el doctor
     * que la tiene pegada a otra cita (o el primero que la tenga libre).
     *
     * @param  Collection<int, Doctor>  $doctors
     * @return Collection<int, array{time: string, doctor_id: int, recommended: bool, waste: int}>
     */
    public function slots(Treatment $treatment, Collection $doctors, CarbonImmutable $date, ?Appointment $moving = null): Collection
    {
        if ($date->gt($this->lastDay())) {
            return collect();
        }

        return $doctors
            ->flatMap(fn (Doctor $doctor) => $this->availability
                ->slotsFor($doctor, $treatment, $date, earliest: $this->earliest(), ignoreAppointmentId: $moving?->getKey())
                ->map(fn (Slot $slot) => [
                    'time' => $slot->label(),
                    'doctor_id' => $doctor->getKey(),
                    'recommended' => $slot->recommended,
                    'waste' => $slot->wasteMinutes,
                ]))
            ->sortBy([['time', 'asc'], ['recommended', 'desc'], ['waste', 'asc']])
            ->unique('time')
            ->values();
    }

    /**
     * Los primeros horarios libres para mostrarlos en el inicio: el primer día con lugar y
     * hasta $limit horarios (primero los que no dejan huecos). Se guardan un minuto.
     *
     * @return array{date: string, times: list<string>}|null
     */
    public function nextFree(Treatment $treatment, int $limit = 4): ?array
    {
        return Cache::remember("web.proximos-horarios.{$treatment->getKey()}.{$limit}", 60, function () use ($treatment, $limit) {
            $doctors = $this->doctorsFor($treatment);
            $day = $this->days($treatment, $doctors, count: 1)[0] ?? null;

            return $day ? [
                'date' => $day->toDateString(),
                'times' => $this->suggested($this->slots($treatment, $doctors, $day))->take($limit)->pluck('time')->all(),
            ] : null;
        });
    }

    /**
     * Lo que se muestra primero: si hay suficientes, solo los horarios que no dejan
     * huecos inútiles en la agenda. El resto queda detrás de "Ver más horarios".
     *
     * @param  Collection<int, array{time: string, doctor_id: int, recommended: bool, waste: int}>  $slots
     * @return Collection<int, array{time: string, doctor_id: int, recommended: bool, waste: int}>
     */
    public function suggested(Collection $slots): Collection
    {
        $tidy = $slots->where('waste', 0)->values();

        return $tidy->count() >= 3 ? $tidy : $slots;
    }
}
