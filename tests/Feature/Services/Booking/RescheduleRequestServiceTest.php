<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\RescheduleRequestStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PatientChangePolicy;
use App\Services\Booking\RescheduleMode;
use App\Services\Booking\RescheduleRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class RescheduleRequestServiceTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private function appointmentTomorrow(): Appointment
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);

        return Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->confirmed()->create();
    }

    public function test_changes_less_than_a_day_ahead_need_approval(): void
    {
        $this->freezeMonday();
        $appointment = $this->appointmentTomorrow();
        $this->travelTo(now()->setTime(12, 0)); // faltan 22 horas

        $mode = app(PatientChangePolicy::class)->rescheduleMode($appointment);

        $this->assertSame(RescheduleMode::NeedsApproval, $mode);
    }

    public function test_changing_doctor_needs_approval_even_with_time_ahead(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-10 10:00')->create();

        $policy = app(PatientChangePolicy::class);

        $this->assertSame(RescheduleMode::Automatic, $policy->rescheduleMode($appointment));
        $this->assertSame(RescheduleMode::NeedsApproval, $policy->rescheduleMode($appointment, doctorId: $doctor->id + 1));
    }

    public function test_patient_proposes_up_to_three_times_and_the_original_appointment_stays(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = $this->appointmentTomorrow();

        $request = app(RescheduleRequestService::class)->requestByPatient($appointment, [
            CarbonImmutable::parse('2026-11-04 10:00'),
            CarbonImmutable::parse('2026-11-04 16:00'),
            CarbonImmutable::parse('2026-11-05 09:00'),
        ], 'Tengo trabajo');

        $this->assertSame(RescheduleRequestStatus::Pending, $request->status);
        $this->assertCount(3, $request->options);
        $this->assertSame('2026-11-03 10:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertGreaterThanOrEqual(1, $reception->notifications()->count());
    }

    public function test_rejects_more_than_three_proposed_times(): void
    {
        $this->freezeMonday();
        $appointment = $this->appointmentTomorrow();

        $this->expectExceptionObject(BookingException::tooManyOptions(3));

        app(RescheduleRequestService::class)->requestByPatient($appointment, [
            CarbonImmutable::parse('2026-11-04 09:00'),
            CarbonImmutable::parse('2026-11-04 10:00'),
            CarbonImmutable::parse('2026-11-04 11:00'),
            CarbonImmutable::parse('2026-11-04 12:00'),
        ]);
    }

    public function test_approving_an_option_moves_the_appointment_and_notifies_the_patient(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = $this->appointmentTomorrow();
        $service = app(RescheduleRequestService::class);
        $request = $service->requestByPatient($appointment, [
            CarbonImmutable::parse('2026-11-04 10:00'),
            CarbonImmutable::parse('2026-11-04 16:00'),
        ]);

        $moved = $service->approve($request, $request->options[1], $reception);

        $this->assertSame('2026-11-04 16:00', $moved->starts_at->format('Y-m-d H:i'));
        $this->assertSame(AppointmentStatus::Confirmed, $moved->status);
        $this->assertSame(RescheduleRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(
            [MessageTemplateKey::RescheduleApproved],
            WhatsappMessage::query()->pending()->get()->pluck('template_key')->all(),
        );
    }

    public function test_rejecting_keeps_the_original_time_and_tells_the_patient(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = $this->appointmentTomorrow();
        $service = app(RescheduleRequestService::class);
        $request = $service->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);

        $service->reject($request, $reception, 'Ese día no hay cupo');

        $this->assertSame(RescheduleRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('2026-11-03 10:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame(MessageTemplateKey::RescheduleRejected, WhatsappMessage::sole()->template_key);
    }

    public function test_a_new_request_replaces_the_previous_pending_one(): void
    {
        $this->freezeMonday();
        $appointment = $this->appointmentTomorrow();
        $service = app(RescheduleRequestService::class);
        $first = $service->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);

        $service->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-05 10:00')]);

        $this->assertSame(RescheduleRequestStatus::Withdrawn, $first->fresh()->status);
    }

    public function test_moving_the_appointment_another_way_closes_the_pending_request_with_the_reason(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = $this->appointmentTomorrow();
        $request = app(RescheduleRequestService::class)->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);

        app(AppointmentService::class)->rescheduleByStaff($appointment, CarbonImmutable::parse('2026-11-05 16:00'), $reception, agreed: true);

        $request->refresh();
        $this->assertSame(RescheduleRequestStatus::Expired, $request->status);
        $this->assertSame('La cita se movió a otro horario.', $request->response_note);
        $this->assertTrue($request->resolvedBy->is($reception));
        $this->assertSame('reschedule_expired', $appointment->events()->latest('id')->first()->type);
    }

    public function test_cancelling_the_appointment_closes_the_pending_request(): void
    {
        $this->freezeMonday();
        $appointment = $this->appointmentTomorrow();
        $request = app(RescheduleRequestService::class)->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);

        app(AppointmentService::class)->cancelByStaff($appointment, User::factory()->reception()->create(), notifyPatient: false);

        $this->assertSame(RescheduleRequestStatus::Expired, $request->fresh()->status);
        $this->assertSame('La cita se canceló.', $request->fresh()->response_note);
    }

    public function test_a_request_is_closed_once_the_patient_shows_up_for_the_original_time(): void
    {
        $this->freezeMonday();
        $appointment = $this->appointmentTomorrow();
        $request = app(RescheduleRequestService::class)->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);
        $this->travelTo('2026-11-03 09:55');

        app(AppointmentService::class)->markArrived($appointment, User::factory()->reception()->create());

        $this->assertSame(RescheduleRequestStatus::Expired, $request->fresh()->status);
        $this->assertSame('El paciente vino a la cita.', $request->fresh()->response_note);
    }

    public function test_requests_of_appointments_that_already_passed_are_closed_on_the_next_catch_up(): void
    {
        $this->freezeMonday();
        $service = app(RescheduleRequestService::class);
        $passed = $service->requestByPatient($this->appointmentTomorrow(), [CarbonImmutable::parse('2026-11-04 10:00')]);
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $later = Appointment::factory()->for($this->doctorOffering($treatment))->for($treatment)->at('2026-11-06 10:00')->confirmed()->create();
        $stillOpen = $service->requestByPatient($later, [CarbonImmutable::parse('2026-11-07 10:00')]);

        $this->travelTo('2026-11-03 10:05');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        $this->assertSame(RescheduleRequestStatus::Expired, $passed->fresh()->status);
        $this->assertSame('La cita ya pasó o se cerró.', $passed->fresh()->response_note);
        $this->assertNull($passed->fresh()->resolved_by_user_id);
        $this->assertSame(RescheduleRequestStatus::Pending, $stillOpen->fresh()->status);
    }

    public function test_offering_another_time_moves_the_appointment_and_asks_the_patient_to_confirm(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = $this->appointmentTomorrow();
        $service = app(RescheduleRequestService::class);
        $request = $service->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);

        $moved = $service->offerAnotherTime($request, CarbonImmutable::parse('2026-11-05 16:00'), $reception);

        $this->assertSame('2026-11-05 16:00', $moved->starts_at->format('Y-m-d H:i'));
        $this->assertSame(AppointmentStatus::Pending, $moved->status);
        $this->assertSame([MessageTemplateKey::Rescheduled], WhatsappMessage::query()->pending()->get()->pluck('template_key')->all());
        $request->refresh();
        $this->assertSame(RescheduleRequestStatus::Approved, $request->status);
        $this->assertNull($request->approved_option_id);
        $this->assertSame('Se le dio otro horario: jue. 05/11 16:00.', $request->response_note);
        $this->assertTrue($request->resolvedBy->is($reception));
    }

    public function test_another_time_already_agreed_with_the_patient_stays_confirmed_without_a_notice(): void
    {
        $this->freezeMonday();
        $appointment = $this->appointmentTomorrow();
        $service = app(RescheduleRequestService::class);
        $request = $service->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);

        $moved = $service->offerAnotherTime($request, CarbonImmutable::parse('2026-11-05 16:00'), User::factory()->reception()->create(), agreed: true);

        $this->assertSame(AppointmentStatus::Confirmed, $moved->status);
        $this->assertSame(0, WhatsappMessage::query()->pending()->count());
    }

    public function test_another_time_cannot_be_offered_on_a_request_already_resolved(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = $this->appointmentTomorrow();
        $service = app(RescheduleRequestService::class);
        $request = $service->requestByPatient($appointment, [CarbonImmutable::parse('2026-11-04 10:00')]);
        $service->reject($request, $reception);

        $this->expectExceptionObject(BookingException::notChangeable());

        $service->offerAnotherTime($request, CarbonImmutable::parse('2026-11-05 16:00'), $reception);
    }
}
