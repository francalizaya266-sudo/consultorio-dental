<?php

namespace Tests\Feature\Services\Reports;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\ScheduleBlockType;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Services\Reports\ActivityReport;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ActivityReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Doctor $doctor;

    private Treatment $treatment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Lima'));
        // Lunes a sábado, 9–13 y 15–19: ocho horas al día.
        $this->doctor = Doctor::factory()->withSchedule()->create(['name' => 'Dra. Ana Torres']);
        $this->treatment = Treatment::factory()->create();
    }

    private function visit(Patient $patient, string $startsAt, AppointmentStatus $status = AppointmentStatus::Completed, int $minutes = 30, array $attributes = []): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->treatment)->for($patient)->at($startsAt, $minutes)->create(['status' => $status, ...$attributes]);
    }

    private function report(string $period = 'month', ?int $doctorId = null): ActivityReport
    {
        return new ActivityReport(ReportPeriod::fromFilters(['period' => $period]), $doctorId);
    }

    public function test_it_counts_attended_visits_no_shows_and_new_patients(): void
    {
        // Nuevo: aún sin primera visita guardada.
        $first = Patient::factory()->create(['first_visit_at' => null]);
        // Nuevo: su primera visita es la de este mes.
        $sameDay = Patient::factory()->create(['first_visit_at' => '2026-10-05 09:00']);
        // Ya era paciente.
        $returning = Patient::factory()->create(['first_visit_at' => '2025-01-10 10:00']);

        $this->visit($first, '2026-10-02 09:00');
        $this->visit($sameDay, '2026-10-05 09:00');
        $this->visit($returning, '2026-10-06 11:00');
        $this->visit($returning, '2026-10-07 11:00', AppointmentStatus::NoShow);
        $this->visit($first, '2026-10-08 10:00', AppointmentStatus::Cancelled);
        $this->visit($returning, '2026-09-20 11:00');

        $report = $this->report();

        $this->assertSame([3, 1, 25.0, 2], [$report->attended(), $report->noShows(), $report->noShowRate(), $report->newPatients()]);
        $this->assertSame(['new' => 2, 'returning' => 1], $report->newVsReturning()['Oct']);
        $this->assertSame(['new' => 0, 'returning' => 1], $report->newVsReturning()['Sep']);
        $this->assertSame(['May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct'], array_keys($report->newVsReturning()));
        $this->assertSame(['Nov 2025', 'Dic 2025', 'Ene'], array_slice(array_keys($report->noShowRateByMonth()), 0, 3));
        $this->assertSame([null, 0.0, 25.0], array_slice(array_values($report->noShowRateByMonth()), -3));

        // Sin citas que debieran haber ocurrido, no hay porcentaje.
        $this->assertNull($this->report('today')->noShowRate());
        // Otro doctor no ve estas citas.
        $this->assertSame(0, $this->report(doctorId: Doctor::factory()->create()->id)->attended());
    }

    public function test_it_finds_the_busiest_hours_including_empty_hours_in_between(): void
    {
        $patient = Patient::factory()->create();
        $this->visit($patient, '2026-10-02 09:00');
        $this->visit($patient, '2026-10-02 09:30', AppointmentStatus::NoShow);
        $this->visit($patient, '2026-10-02 12:00', AppointmentStatus::Confirmed);
        $this->visit($patient, '2026-10-02 15:00', AppointmentStatus::Cancelled);

        $this->assertSame([9 => 2, 10 => 0, 11 => 0, 12 => 1], $this->report()->busiestHours());
        $this->assertSame([], $this->report('today')->busiestHours());
    }

    public function test_origins_come_from_the_booking_or_else_from_the_patient(): void
    {
        $fromFacebook = Patient::factory()->create(['origin' => AppointmentOrigin::Facebook]);
        $unknown = Patient::factory()->create(['origin' => null]);

        $this->visit($fromFacebook, '2026-10-02 09:00');
        $this->visit($fromFacebook, '2026-10-03 09:00', attributes: ['origin' => AppointmentOrigin::Google]);
        $this->visit($unknown, '2026-10-05 09:00', attributes: ['origin' => AppointmentOrigin::Google]);
        $this->visit($unknown, '2026-10-06 09:00');
        $this->visit($unknown, '2026-10-07 09:00');
        $this->visit($unknown, '2026-10-08 09:00');
        $this->visit($unknown, '2026-10-09 09:00', AppointmentStatus::Cancelled, attributes: ['origin' => AppointmentOrigin::Instagram]);

        // "Sin dato" siempre al final, aunque sea el más grande.
        $this->assertSame(['Google' => 2, 'Facebook' => 1, ActivityReport::NO_ORIGIN => 3], $this->report()->origins());
    }

    public function test_occupancy_is_the_booked_share_of_the_open_hours_after_blocks(): void
    {
        $patient = Patient::factory()->create();
        // Lunes 19: abre 8 horas, pero la tarde está bloqueada por un curso. Quedan 4 horas, 2 reservadas.
        ScheduleBlock::query()->create(['doctor_id' => $this->doctor->id, 'type' => ScheduleBlockType::Course, 'starts_at' => '2026-10-19 15:00', 'ends_at' => '2026-10-19 19:00']);
        $this->visit($patient, '2026-10-19 09:00', minutes: 90);
        $this->visit($patient, '2026-10-19 11:00', AppointmentStatus::NoShow);
        $this->visit($patient, '2026-10-19 12:00', AppointmentStatus::Cancelled);

        $monday = new ActivityReport(new ReportPeriod(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19')));
        $sunday = new ActivityReport(new ReportPeriod(CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-10-18')));

        $this->assertSame(['Dra. Ana Torres' => 50], $monday->occupancyByDoctor());
        // Un día sin horario no tiene ocupación que mostrar.
        $this->assertSame([], $sunday->occupancyByDoctor());
    }

    public function test_occupancy_by_weekday_shows_which_days_have_room(): void
    {
        $patient = Patient::factory()->create();
        // Lunes 19: 4 horas abiertas (la tarde, bloqueada) y 2 reservadas. Martes 20: 8 abiertas y 2 reservadas.
        ScheduleBlock::query()->create(['doctor_id' => $this->doctor->id, 'type' => ScheduleBlockType::Course, 'starts_at' => '2026-10-19 15:00', 'ends_at' => '2026-10-19 19:00']);
        $this->visit($patient, '2026-10-19 09:00', minutes: 120);
        $this->visit($patient, '2026-10-20 09:00', AppointmentStatus::NoShow, minutes: 120);
        $this->visit($patient, '2026-10-21 09:00', AppointmentStatus::Cancelled, minutes: 120);

        $week = new ActivityReport(new ReportPeriod(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-25')->endOfDay()));

        // El domingo no atiende, así que no aparece.
        $this->assertSame(['Lunes' => 50, 'Martes' => 25, 'Miércoles' => 0, 'Jueves' => 0, 'Viernes' => 0, 'Sábado' => 0], $week->occupancyByWeekday());
    }

    public function test_income_by_month_covers_the_last_twelve_months_without_voided_payments(): void
    {
        Payment::factory()->create(['doctor_id' => $this->doctor->id, 'amount' => 200, 'paid_at' => '2026-10-02 10:00']);
        Payment::factory()->create(['amount' => 80, 'paid_at' => '2026-10-03 10:00']);
        Payment::factory()->create(['amount' => 500, 'paid_at' => '2026-10-04 10:00', 'voided_at' => now(), 'void_reason' => 'Error']);
        Payment::factory()->create(['amount' => 120, 'paid_at' => '2025-11-15 10:00']);
        Payment::factory()->create(['amount' => 999, 'paid_at' => '2025-10-31 10:00']);

        $months = $this->report()->incomeByMonth();

        $this->assertCount(12, $months);
        $this->assertSame(['Nov 2025' => 120.0, 'Oct' => 280.0], array_filter($months));
        $this->assertSame(['Oct' => 200.0], array_filter($this->report(doctorId: $this->doctor->id)->incomeByMonth()));
    }
}
