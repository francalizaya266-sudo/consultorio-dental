<?php

namespace App\Livewire\Site\Concerns;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Services\Booking\WebAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Elegir doctor, día y hora en la web: tira de días con horarios libres y los
 * horarios del día elegido, primero los que no dejan huecos en la agenda.
 */
trait PicksSlots
{
    #[Locked]
    public ?int $treatmentId = null;

    /** Null = el primero que esté libre. */
    #[Locked]
    public ?int $doctorId = null;

    #[Locked]
    public ?string $date = null;

    #[Locked]
    public bool $showAllTimes = false;

    /** @var list<string> Días con horarios (Y-m-d), calculados al elegir tratamiento o doctor. */
    #[Locked]
    public array $availableDays = [];

    /** Cita que se está moviendo (su horario no cuenta como ocupado). */
    protected function movingAppointment(): ?Appointment
    {
        return null;
    }

    #[Computed]
    public function treatment(): ?Treatment
    {
        return $this->treatmentId ? Treatment::find($this->treatmentId) : null;
    }

    /** @return Collection<int, Doctor> */
    #[Computed]
    public function doctors(): Collection
    {
        return $this->treatment ? app(WebAvailability::class)->doctorsFor($this->treatment) : collect();
    }

    /** @return Collection<int, Doctor> Los doctores entre los que se buscan horarios. */
    protected function searchedDoctors(): Collection
    {
        return $this->doctorId ? $this->doctors->where('id', $this->doctorId)->values() : $this->doctors;
    }

    public function selectDoctor(?int $doctorId): void
    {
        $this->doctorId = $doctorId && $this->doctors->contains('id', $doctorId) ? $doctorId : null;
        $this->refreshDays();
    }

    public function selectDate(string $date): void
    {
        if (in_array($date, $this->availableDays, true)) {
            $this->date = $date;
            $this->showAllTimes = false;
            unset($this->daySlots);
        }
    }

    public function showMoreTimes(): void
    {
        $this->showAllTimes = true;
    }

    protected function refreshDays(): void
    {
        $this->availableDays = $this->treatment
            ? array_map(fn (CarbonImmutable $day) => $day->toDateString(), app(WebAvailability::class)->days($this->treatment, $this->searchedDoctors(), moving: $this->movingAppointment()))
            : [];

        if (! in_array($this->date, $this->availableDays, true)) {
            $this->date = $this->availableDays[0] ?? null;
        }

        $this->showAllTimes = false;
        unset($this->daySlots);
    }

    /** @return Collection<int, array{time: string, doctor_id: int, recommended: bool, waste: int}> */
    #[Computed]
    public function daySlots(): Collection
    {
        if (! $this->treatment || ! $this->date) {
            return collect();
        }

        return app(WebAvailability::class)->slots($this->treatment, $this->searchedDoctors(), CarbonImmutable::parse($this->date), $this->movingAppointment());
    }

    /** @return Collection<int, array{time: string, doctor_id: int, recommended: bool, waste: int}> */
    #[Computed]
    public function visibleSlots(): Collection
    {
        return $this->showAllTimes ? $this->daySlots : app(WebAvailability::class)->suggested($this->daySlots);
    }

    /** El horario elegido, si sigue libre: [inicio, doctor]. */
    protected function resolveSlot(string $date, string $time): ?array
    {
        $this->date = $date;
        unset($this->daySlots);
        $slot = $this->daySlots->firstWhere('time', $time);

        return $slot ? [CarbonImmutable::parse($date.' '.$time), $this->doctors->firstWhere('id', $slot['doctor_id'])] : null;
    }

    /** "Lunes 3 de noviembre". */
    public static function dayLabel(CarbonImmutable|string $date): string
    {
        return ucfirst(CarbonImmutable::parse($date)->translatedFormat('l j \d\e F'));
    }

    /** "10:30 a. m." como lo lee el paciente, con espacios que no se cortan al final de la línea. */
    public static function timeLabel(string $time): string
    {
        $time = CarbonImmutable::parse($time);

        return $time->format('g:i')."\u{00A0}".($time->hour < 12 ? "a.\u{00A0}m." : "p.\u{00A0}m.");
    }
}
