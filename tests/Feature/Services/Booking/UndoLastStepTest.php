<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\CancelledBy;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Deshacer un "Llegó", "No asistió", "Confirmar" o "Cancelar" marcado por error. */
class UndoLastStepTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->reception = User::factory()->reception()->create();
    }

    private function service(): AppointmentService
    {
        return app(AppointmentService::class);
    }

    private function appointmentAt(string $startsAt): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->treatment)->at($startsAt)->confirmed()->create();
    }

    public function test_a_no_show_marked_by_mistake_goes_back_to_confirmed_and_stays_in_the_history(): void
    {
        $appointment = $this->appointmentAt('2026-11-02 09:00');
        $this->travelTo('2026-11-02 10:00');
        $this->service()->markNoShow($appointment, $this->reception);

        $undone = $this->service()->undoLastStep($appointment, $this->reception);

        $this->assertSame(AppointmentStatus::NoShow, $undone);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->no_show_at);
        $this->assertSame(['no_show', 'undone'], $appointment->events()->orderBy('id')->pluck('type')->all());
        $this->assertSame(['step' => 'no_show'], $appointment->events()->latest('id')->first()->meta);
    }

    public function test_a_no_show_on_a_visit_nobody_confirmed_goes_back_to_pending(): void
    {
        $appointment = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-02 09:00')->create();
        $this->travelTo('2026-11-02 10:00');
        $this->service()->markNoShow($appointment, $this->reception);

        $this->service()->undoLastStep($appointment, $this->reception);

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_an_arrival_marked_by_mistake_takes_the_patient_out_of_the_waiting_room(): void
    {
        $appointment = $this->appointmentAt('2026-11-02 09:00');
        $this->service()->markArrived($appointment, $this->reception);
        $this->assertSame(1, $this->doctor->user->unreadNotifications()->count());

        $this->service()->undoLastStep($appointment, $this->reception);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->checked_in_at);
        $this->assertSame(0, $this->doctor->user->unreadNotifications()->count(), 'El doctor ya no tiene a nadie esperando.');
    }

    public function test_a_confirmation_made_by_hand_goes_back_to_pending(): void
    {
        $appointment = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-03 09:00')->create();
        $this->travel(10)->minutes();
        $this->service()->confirmByStaff($appointment, $this->reception);

        $this->service()->undoLastStep($appointment, $this->reception);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertNull($appointment->confirmed_at);
        $this->assertNull($appointment->confirmed_via);
    }

    public function test_a_visit_booked_by_staff_is_born_confirmed_and_has_nothing_to_undo(): void
    {
        $appointment = $this->service()->bookFromStaff($this->reception, Patient::factory()->create(), $this->doctor, $this->treatment, CarbonImmutable::parse('2026-11-03 09:00'));

        $this->assertNull($this->service()->undoableStep($appointment));

        $this->expectExceptionObject(BookingException::nothingToUndo());

        $this->service()->undoLastStep($appointment, $this->reception);
    }

    public function test_a_cancellation_by_the_clinic_puts_the_visit_back_and_drops_the_unsent_notice(): void
    {
        $appointment = $this->appointmentAt('2026-11-03 09:00');
        $this->service()->cancelByStaff($appointment, $this->reception, 'Se equivocó de paciente');
        $notice = WhatsappMessage::query()->where('template_key', MessageTemplateKey::Cancelled)->sole();

        $this->service()->undoLastStep($appointment, $this->reception);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->cancelled_by);
        $this->assertNull($appointment->cancellation_reason);
        $this->assertSame(MessageStatus::Cancelled, $notice->fresh()->status);
    }

    public function test_a_cancelled_visit_does_not_come_back_on_top_of_another_one(): void
    {
        $appointment = $this->appointmentAt('2026-11-03 09:00');
        $this->service()->cancelByStaff($appointment, $this->reception);
        Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-03 09:00')->create();

        try {
            $this->service()->undoLastStep($appointment, $this->reception);
            $this->fail('Se esperaba que no dejara volver la cita a un horario ocupado.');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::slotNoLongerFree()->getMessage(), $e->getMessage());
        }

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_a_cancellation_made_by_the_patient_is_not_undone_by_staff(): void
    {
        $appointment = $this->appointmentAt('2026-11-05 09:00');
        $this->service()->cancelByPatient($appointment);

        $this->assertSame(CancelledBy::Patient, $appointment->fresh()->cancelled_by);
        $this->assertNull($this->service()->undoableStep($appointment->fresh()));
    }

    public function test_after_a_day_there_is_nothing_left_to_undo(): void
    {
        $appointment = $this->appointmentAt('2026-11-02 09:00');
        $this->travelTo('2026-11-02 10:00');
        $this->service()->markNoShow($appointment, $this->reception);

        $this->travelTo('2026-11-03 09:59');
        $this->assertSame(AppointmentStatus::NoShow, $this->service()->undoableStep($appointment));

        $this->travelTo('2026-11-03 10:01');
        $this->assertNull($this->service()->undoableStep($appointment));
    }

    public function test_a_doctor_cannot_undo_a_step_on_another_doctors_agenda(): void
    {
        $appointment = $this->appointmentAt('2026-11-02 09:00');
        $this->service()->markArrived($appointment, $this->reception);
        $other = $this->doctorOffering($this->treatment);

        $this->expectException(AuthorizationException::class);

        $this->service()->undoLastStep($appointment, $other->user);
    }
}
