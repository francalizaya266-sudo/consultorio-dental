<?php

namespace Tests\Feature\Services\Agenda;

use App\Enums\ScheduleBlockType;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\Slot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private function labels(iterable $slots): array
    {
        return collect($slots)->map(fn (Slot $slot) => $slot->label())->all();
    }

    public function test_offers_only_starts_where_the_full_treatment_fits_before_closing(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 45, 'buffer_minutes' => 10]);
        $doctor = $this->doctorOffering($treatment);

        $labels = $this->labels(app(AvailabilityService::class)->slotsFor($doctor, $treatment, $monday));

        $this->assertSame('09:00', $labels[0]);
        $this->assertContains('12:15', $labels, 'Una cita de 45 min puede empezar 12:15 y terminar al cierre de las 13:00.');
        $this->assertNotContains('12:30', $labels);
        $this->assertNotContains('13:00', $labels);
        $this->assertContains('15:00', $labels);
        $this->assertSame('18:15', end($labels));
    }

    public function test_reserves_sterilization_time_after_existing_appointments(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 10]);
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00', 30, 10)->create();

        $labels = $this->labels(app(AvailabilityService::class)->slotsFor($doctor, $treatment, $monday));

        // 10:00–10:30 ocupado + 10 min de esterilización: lo siguiente libre es 10:40.
        $this->assertNotContains('10:15', $labels);
        $this->assertNotContains('10:30', $labels);
        $this->assertContains('10:40', $labels);
        // Antes de la cita, nuestra cita también necesita sus 10 min: la última es 9:20.
        $this->assertContains('09:20', $labels);
        $this->assertNotContains('09:30', $labels);
    }

    public function test_recommends_slots_next_to_existing_appointments(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 10]);
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00', 30, 10)->create();

        $recommended = app(AvailabilityService::class)
            ->slotsFor($doctor, $treatment, $monday)
            ->filter(fn (Slot $slot) => $slot->recommended);

        $this->assertSame(['09:20', '10:40'], $this->labels($recommended->values()));
    }

    public function test_ignores_cancelled_appointments(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->cancelled()->create();

        $labels = $this->labels(app(AvailabilityService::class)->slotsFor($doctor, $treatment, $monday));

        $this->assertContains('10:00', $labels);
    }

    public function test_skips_blocked_time_for_the_doctor_and_for_the_whole_clinic(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        ScheduleBlock::create(['doctor_id' => $doctor->id, 'type' => ScheduleBlockType::Course, 'starts_at' => '2026-11-02 09:00', 'ends_at' => '2026-11-02 11:00']);
        ScheduleBlock::create(['doctor_id' => null, 'type' => ScheduleBlockType::Holiday, 'starts_at' => '2026-11-02 15:00', 'ends_at' => '2026-11-02 19:00']);

        $labels = $this->labels(app(AvailabilityService::class)->slotsFor($doctor, $treatment, $monday));

        $this->assertSame('11:00', $labels[0]);
        $this->assertSame('12:30', end($labels));
    }

    public function test_ranges_that_overlap_or_touch_count_as_one_stretch(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 60, 'buffer_minutes' => 0]);
        // Un horario que quedó cruzado (9–13 y 12–16) y un tramo pegado al anterior (16–17).
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00'], ['12:00', '16:00'], ['16:00', '17:00']], [1])->create();
        $doctor->treatments()->attach($treatment);
        $availability = app(AvailabilityService::class);

        $this->assertSame(
            [['09:00', '17:00']],
            $availability->workingRanges($doctor, $monday)->map(fn (array $range) => [$range['start']->format('H:i'), $range['end']->format('H:i')])->all(),
        );

        // Cada hora se ofrece una sola vez, y una cita puede pasar de un tramo al siguiente.
        $labels = $this->labels($availability->slotsFor($doctor, $treatment, $monday));
        $this->assertSame(array_unique($labels), $labels);
        $this->assertContains('12:30', $labels);
        $this->assertContains('15:30', $labels);
        $this->assertSame('16:00', end($labels));
    }

    public function test_finds_the_upcoming_appointments_that_fall_outside_the_weekly_schedule(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();
        $late = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 18:45')->create();
        $sunday = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-08 10:00')->create();
        // Las canceladas y las que ya pasaron no cuentan.
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-15 10:00')->cancelled()->create();
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-01 10:00')->create();

        $outside = app(AvailabilityService::class)->outsideSchedule($doctor);

        // La de las 18:45 termina después del cierre de las 19:00; la del domingo cae en un día que no atiende.
        $this->assertSame([$late->id, $sunday->id], $outside->map(fn (Appointment $appointment) => $appointment->id)->all());
    }

    public function test_returns_nothing_on_a_day_the_doctor_does_not_work(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);

        $slots = app(AvailabilityService::class)->slotsFor($doctor, $treatment, now()->next('Sunday'));

        $this->assertCount(0, $slots);
    }

    public function test_uses_the_doctors_own_duration_for_the_treatment(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering();
        $doctor->treatments()->attach($treatment, ['duration_minutes' => 60]);

        $slots = app(AvailabilityService::class)->slotsFor($doctor, $treatment, $monday);

        $this->assertSame('10:00', $slots->first()->endsAt->format('H:i'));
        $this->assertSame('12:00', $slots->filter(fn (Slot $s) => $s->startsAt->hour < 13)->last()->label());
    }

    public function test_hides_slots_before_the_minimum_lead_time(): void
    {
        $monday = $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);

        $labels = $this->labels(app(AvailabilityService::class)->slotsFor($doctor, $treatment, $monday, earliest: $monday->setTime(10, 5)));

        $this->assertSame('10:15', $labels[0]);
    }

    public function test_detects_overlap_when_checking_a_specific_time(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $existing = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00', 30, 10)->create();
        $service = app(AvailabilityService::class);

        $this->assertFalse($service->isFree($doctor, now()->setTime(10, 35), 30, 10));
        $this->assertTrue($service->isFree($doctor, now()->setTime(10, 40), 30, 10));
        $this->assertTrue($service->isFree($doctor, now()->setTime(10, 0), 30, 10, ignoreAppointmentId: $existing->id));
        $this->assertFalse($service->isFree($doctor, now()->setTime(13, 30), 30, 10), 'Fuera del horario de atención.');
        $this->assertTrue($service->isFree($doctor, now()->setTime(13, 30), 30, 10, requireWorkingHours: false));
    }
}
