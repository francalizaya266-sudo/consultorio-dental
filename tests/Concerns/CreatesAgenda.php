<?php

namespace Tests\Concerns;

use App\Models\Doctor;
use App\Models\Treatment;
use Carbon\CarbonImmutable;

/** Ayudas para armar una agenda de prueba en una fecha fija. */
trait CreatesAgenda
{
    /** Lunes 2 de noviembre de 2026, 8:00 a. m. en Lima. */
    protected function freezeMonday(): CarbonImmutable
    {
        $monday = CarbonImmutable::parse('2026-11-02 08:00', 'America/Lima');
        $this->travelTo($monday);

        return $monday;
    }

    /** Doctor de lunes a sábado, 9:00–13:00 y 15:00–19:00, que realiza los tratamientos dados. */
    protected function doctorOffering(Treatment ...$treatments): Doctor
    {
        $doctor = Doctor::factory()->withSchedule()->create();
        $doctor->treatments()->attach(collect($treatments)->pluck('id'));

        return $doctor;
    }
}
