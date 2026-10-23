<?php

namespace App\Services\Agenda;

use Carbon\CarbonImmutable;

/** Horario disponible para una cita. */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        // Pegado a una cita existente: se ofrece primero para no dejar huecos.
        public bool $recommended,
        // Minutos de huecos inútiles (muy cortos para otra cita) que dejaría este horario.
        public int $wasteMinutes,
    ) {}

    public function label(): string
    {
        return $this->startsAt->format('H:i');
    }

    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'label' => $this->label(),
            'recommended' => $this->recommended,
            'waste_minutes' => $this->wasteMinutes,
        ];
    }
}
