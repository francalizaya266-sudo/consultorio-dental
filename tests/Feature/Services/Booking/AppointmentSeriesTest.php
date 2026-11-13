<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\CancelledBy;
use App\Enums\MessageTemplateKey;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AppointmentSeriesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private User $reception;

    private Treatment $control;

    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->reception = User::factory()->reception()->create();
        $this->control = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->control);
        $this->patient = Patient::factory()->create();
    }

    public function test_a_series_repeats_the_weekday_and_the_time_and_tells_the_patient_once(): void
    {
        $result = $this->series('2026-11-03 10:00', everyWeeks: 2, times: 4);

        $this->assertSame(
            ['2026-11-03 10:00', '2026-11-17 10:00', '2026-12-01 10:00', '2026-12-15 10:00'],
            array_map(fn (Appointment $appointment) => $appointment->starts_at->format('Y-m-d H:i'), $result['booked']),
        );
        $this->assertSame([], $result['skipped']);
        $this->assertCount(1, Appointment::query()->whereBelongsTo($this->patient)->pluck('series_id')->filter()->unique());
        $this->assertSame([AppointmentStatus::Confirmed->value], Appointment::query()->pluck('status')->map->value->unique()->all());

        // Un solo aviso, el de la primera: de las demás le llega su recordatorio.
        $notice = WhatsappMessage::sole();
        $this->assertSame([$result['booked'][0]->id, MessageTemplateKey::AppointmentScheduled], [$notice->appointment_id, $notice->template_key]);
    }

    public function test_a_taken_time_moves_to_the_nearest_free_one_and_a_day_without_room_is_skipped(): void
    {
        // El 10 esa hora ya es de otro paciente; el 17 es feriado.
        Appointment::factory()->for($this->doctor)->for($this->control)->confirmed()->at('2026-11-10 09:00')->create();
        ScheduleBlock::query()->create(['doctor_id' => null, 'type' => 'holiday', 'starts_at' => '2026-11-17 00:00', 'ends_at' => '2026-11-18 00:00']);

        $plan = app(AppointmentService::class)->seriesPlan($this->doctor, $this->control, CarbonImmutable::parse('2026-11-03 09:00'), 1, 4, $this->patient->id);

        $this->assertSame(
            [['2026-11-10', '09:40', true], ['2026-11-17', null, false], ['2026-11-24', '09:00', false]],
            array_map(fn (array $step) => [$step['date']->toDateString(), $step['startsAt']?->format('H:i'), $step['moved']], $plan),
        );

        $result = $this->series('2026-11-03 09:00', everyWeeks: 1, times: 4);

        $this->assertSame(
            ['2026-11-03 09:00', '2026-11-10 09:40', '2026-11-24 09:00'],
            array_map(fn (Appointment $appointment) => $appointment->starts_at->format('Y-m-d H:i'), $result['booked']),
        );
        $this->assertSame(['2026-11-17'], array_map(fn (CarbonImmutable $date) => $date->toDateString(), $result['skipped']));
    }

    public function test_only_the_first_can_be_an_overbooking_and_every_one_keeps_the_duration_set_by_hand(): void
    {
        Appointment::factory()->for($this->doctor)->for($this->control)->confirmed()->at('2026-11-03 10:00')->create();

        $result = $this->series('2026-11-03 10:00', everyWeeks: 4, times: 3, options: ['overbook' => true, 'duration_minutes' => 45]);

        $this->assertSame(
            [[true, '10:45', true], [false, '10:45', true], [false, '10:45', true]],
            array_map(fn (Appointment $appointment) => [$appointment->is_overbooked, $appointment->ends_at->format('H:i'), $appointment->custom_duration], $result['booked']),
        );
    }

    public function test_a_series_never_goes_past_the_most_times_allowed(): void
    {
        $result = $this->series('2026-11-03 10:00', everyWeeks: 1, times: 40);

        $this->assertCount(AppointmentService::SERIES_MAX_TIMES, $result['booked']);
    }

    public function test_cancelling_one_can_take_the_ones_that_follow_with_a_single_notice(): void
    {
        [$first, $second, $third, $fourth] = $this->series('2026-11-03 10:00', everyWeeks: 1, times: 4)['booked'];
        WhatsappMessage::query()->delete();

        $cancelled = app(AppointmentService::class)->cancelByStaff($second, $this->reception, 'Se muda de ciudad', true, CancelledBy::Patient, withLaterInSeries: true);

        $this->assertSame(3, $cancelled);
        $this->assertSame(
            [AppointmentStatus::Confirmed, AppointmentStatus::Cancelled, AppointmentStatus::Cancelled, AppointmentStatus::Cancelled],
            [$first->fresh()->status, $second->fresh()->status, $third->fresh()->status, $fourth->fresh()->status],
        );
        $this->assertSame([CancelledBy::Patient, 'Se muda de ciudad', $this->reception->id], [$fourth->fresh()->cancelled_by, $fourth->fresh()->cancellation_reason, $fourth->fresh()->cancelled_by_user_id]);
        $this->assertSame([$second->id], WhatsappMessage::query()->where('template_key', MessageTemplateKey::Cancelled)->pluck('appointment_id')->all());
    }

    public function test_cancelling_one_alone_leaves_the_rest_of_the_series(): void
    {
        [$first, $second, $third] = $this->series('2026-11-03 10:00', everyWeeks: 1, times: 3)['booked'];

        $this->assertSame(1, app(AppointmentService::class)->cancelByStaff($second, $this->reception));
        $this->assertSame([AppointmentStatus::Confirmed, AppointmentStatus::Confirmed], [$first->fresh()->status, $third->fresh()->status]);
    }

    public function test_each_appointment_knows_its_place_among_the_ones_still_standing(): void
    {
        [$first, $second, $third] = $this->series('2026-11-03 10:00', everyWeeks: 1, times: 3)['booked'];
        $alone = app(AppointmentService::class)->bookFromStaff($this->reception, $this->patient, $this->doctor, $this->control, CarbonImmutable::parse('2026-11-05 10:00'));

        $this->assertSame(['position' => 3, 'total' => 3], $third->seriesPosition());
        $this->assertNull($alone->seriesPosition());

        app(AppointmentService::class)->cancelByStaff($second, $this->reception);

        $this->assertSame(['position' => 2, 'total' => 2], $third->fresh()->seriesPosition());
        // La cancelada todavía dice cuál era.
        $this->assertSame(['position' => 2, 'total' => 3], $second->fresh()->seriesPosition());
        $this->assertSame([$third->id], Appointment::query()->laterInSeries($first)->pluck('id')->all());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{booked: list<Appointment>, skipped: list<CarbonImmutable>}
     */
    private function series(string $startsAt, int $everyWeeks, int $times, array $options = []): array
    {
        return app(AppointmentService::class)->bookSeries($this->reception, $this->patient, $this->doctor, $this->control, CarbonImmutable::parse($startsAt), $everyWeeks, $times, $options);
    }
}
