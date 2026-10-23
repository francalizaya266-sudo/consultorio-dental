<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\BookingException;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\WebBookingData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AppointmentServiceTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private function webBooking(Doctor $doctor, Treatment $treatment, string $startsAt, array $overrides = []): WebBookingData
    {
        return new WebBookingData(...array_merge([
            'doctorId' => $doctor->id,
            'treatmentId' => $treatment->id,
            'startsAt' => CarbonImmutable::parse($startsAt),
            'phone' => '987 654 321',
            'firstName' => 'María',
            'lastName' => 'Quispe',
            'acceptsPrivacy' => true,
        ], $overrides));
    }

    private function service(): AppointmentService
    {
        return app(AppointmentService::class);
    }

    public function test_web_booking_is_reserved_instantly_and_creates_the_account_and_patient(): void
    {
        $this->freezeMonday();
        User::factory()->reception()->create();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);

        $appointment = $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00'));

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame(BookingChannel::Web, $appointment->booked_via);
        $this->assertTrue($appointment->is_new_patient);
        $this->assertSame('+51987654321', $appointment->patient->account->phone);
        $this->assertSame(PatientRelationship::Self, $appointment->patient->relationship);
        $this->assertNotNull($appointment->patient->account->privacy_accepted_at);
        $this->assertNull($appointment->patient->account->phone_verified_at, 'El celular se verifica al tocar el enlace, no al reservar.');
    }

    public function test_web_booking_prepares_the_confirmation_message_and_alerts_reception(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);

        $appointment = $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00'));

        $message = WhatsappMessage::sole();
        $this->assertSame(MessageTemplateKey::BookingConfirmation, $message->template_key);
        $this->assertSame(MessageStatus::Pending, $message->status);
        $this->assertTrue($message->appointment->is($appointment));
        $this->assertSame(1, $reception->notifications()->count());
    }

    public function test_rejects_a_web_booking_on_a_taken_slot(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();

        $this->expectExceptionObject(BookingException::slotTaken());

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:15'));
    }

    public function test_rejects_web_booking_of_a_treatment_that_needs_evaluation(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->clinicOnly()->create();
        $doctor = $this->doctorOffering($treatment);

        $this->expectExceptionObject(BookingException::treatmentNotBookableOnline());

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00'));
    }

    public function test_rejects_web_booking_inside_the_minimum_lead_time(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);

        $this->expectExceptionObject(BookingException::tooSoon());

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-02 09:00'));
    }

    public function test_rejects_web_booking_from_a_blocked_number(): void
    {
        $this->freezeMonday();
        Account::factory()->blocked()->create(['phone' => '+51987654321']);
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);

        $this->expectExceptionObject(BookingException::accountBlocked());

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00'));
    }

    public function test_limits_each_patient_to_two_future_web_appointments(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);
        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00'));
        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-04 10:00'));

        $this->expectExceptionObject(BookingException::patientLimit(2));

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-05 10:00'));
    }

    public function test_lets_a_family_book_for_several_members_up_to_the_phone_limit(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);
        Account::factory()->create(['phone' => '+51987654321', 'web_booking_limit' => 3]);

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 09:00'));
        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00', ['firstName' => 'Mateo', 'lastName' => 'Rojas', 'relationship' => PatientRelationship::Child]));
        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 11:00', ['firstName' => 'Rosa', 'lastName' => 'Huamán', 'relationship' => PatientRelationship::Parent]));

        $this->assertSame(3, Account::sole()->patients()->count());

        $this->expectExceptionObject(BookingException::phoneLimit(3));

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 12:00', ['firstName' => 'Lucía', 'lastName' => 'Rojas', 'relationship' => PatientRelationship::Child]));
    }

    public function test_reuses_the_family_member_with_the_same_name_instead_of_duplicating(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);
        $account = Account::factory()->create(['phone' => '+51987654321']);
        $maria = Patient::factory()->for($account)->create(['first_name' => 'María', 'last_name' => 'Quispe']);

        $appointment = $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00', ['firstName' => ' maría ', 'lastName' => 'QUISPE']));

        $this->assertTrue($appointment->patient->is($maria));
    }

    public function test_forbids_choosing_a_registered_family_member_from_an_unrecognized_device(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);
        $account = Account::factory()->create(['phone' => '+51987654321']);
        $child = Patient::factory()->dependentOf($account)->create();

        $this->expectException(AuthorizationException::class);

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00', ['patientId' => $child->id]));
    }

    public function test_books_for_a_registered_family_member_from_a_remembered_device(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);
        $account = Account::factory()->verified()->create(['phone' => '+51987654321']);
        $child = Patient::factory()->dependentOf($account)->create();
        $device = RememberedDevice::create(['account_id' => $account->id, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addMonth()]);

        $appointment = $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00', ['patientId' => $child->id]), $device);

        $this->assertTrue($appointment->patient->is($child));
        $this->assertTrue($appointment->from_verified_device);
    }

    public function test_patients_in_treatment_cannot_book_sessions_on_the_web(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($treatment);
        $account = Account::factory()->create(['phone' => '+51987654321']);
        $patient = Patient::factory()->for($account)->create(['first_name' => 'María', 'last_name' => 'Quispe']);
        $patient->treatmentPlans()->create(['doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::InProgress]);

        $this->expectExceptionObject(BookingException::patientInTreatment());

        $this->service()->bookFromWeb($this->webBooking($doctor, $treatment, '2026-11-03 10:00'));
    }

    public function test_doctor_schedules_the_next_session_confirmed_and_reception_is_alerted(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->clinicOnly()->create(['duration_minutes' => 60]);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create();

        $appointment = $this->service()->bookFromStaff($doctor->user, $patient, $doctor, $treatment, CarbonImmutable::parse('2026-11-09 15:00'), ['fixed_date' => true]);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(BookingChannel::Doctor, $appointment->booked_via);
        $this->assertTrue($appointment->fixed_date);
        $this->assertSame(MessageTemplateKey::AppointmentScheduled, WhatsappMessage::sole()->template_key);
        $this->assertSame(1, $reception->notifications()->count());
    }

    public function test_forbids_a_doctor_from_booking_on_another_doctors_agenda(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $otherDoctor = $this->doctorOffering($treatment);

        $this->expectException(AuthorizationException::class);

        $this->service()->bookFromStaff($doctor->user, Patient::factory()->create(), $otherDoctor, $treatment, CarbonImmutable::parse('2026-11-03 10:00'));
    }

    public function test_staff_can_book_outside_working_hours_but_never_on_top_of_another_appointment(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);

        $late = $this->service()->bookFromStaff($reception, Patient::factory()->create(), $doctor, $treatment, CarbonImmutable::parse('2026-11-03 19:00'));
        $this->assertSame('19:30', $late->ends_at->format('H:i'));

        $this->expectExceptionObject(BookingException::overlaps());
        $this->service()->bookFromStaff($reception, Patient::factory()->create(), $doctor, $treatment, CarbonImmutable::parse('2026-11-03 19:15'));
    }

    public function test_patient_link_confirmation_marks_the_appointment_confirmed(): void
    {
        $this->freezeMonday();
        $appointment = Appointment::factory()->create(['unconfirmed_flagged_at' => now()]);

        $this->service()->confirmByPatient($appointment);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->unconfirmed_flagged_at);
        $this->assertSame('confirmed', $appointment->events()->sole()->type);
    }

    public function test_patient_cancels_before_the_deadline_and_frees_the_slot(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = Appointment::factory()->at('2026-11-04 10:00')->create();
        WhatsappMessage::create(['appointment_id' => $appointment->id, 'template_key' => MessageTemplateKey::Reminder, 'phone' => '+51987654321']);

        $this->service()->cancelByPatient($appointment, 'Viaje');

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertSame(MessageStatus::Cancelled, WhatsappMessage::sole()->status, 'El recordatorio pendiente se descarta.');
        $this->assertSame(1, $reception->notifications()->count());
    }

    public function test_patient_cannot_cancel_after_the_deadline(): void
    {
        $this->freezeMonday();
        $appointment = Appointment::factory()->at('2026-11-02 15:00')->create();

        $this->expectExceptionObject(BookingException::tooLateToCancel(12));

        $this->service()->cancelByPatient($appointment);
    }

    public function test_patient_moves_their_own_appointment_more_than_a_day_ahead(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-05 10:00')->create();

        $moved = $this->service()->rescheduleByPatient($appointment, CarbonImmutable::parse('2026-11-06 11:00'));

        $this->assertSame('2026-11-06 11:00', $moved->starts_at->format('Y-m-d H:i'));
        $this->assertSame(AppointmentStatus::Confirmed, $moved->status);
        $this->assertSame(1, $moved->reschedule_count);
        $this->assertSame(1, $reception->notifications()->count());
    }

    public function test_patient_cannot_move_a_fixed_date_session_directly(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-05 10:00')->create(['fixed_date' => true]);

        $this->expectExceptionObject(BookingException::notChangeable());

        $this->service()->rescheduleByPatient($appointment, CarbonImmutable::parse('2026-11-06 11:00'));
    }

    public function test_reception_move_asks_the_patient_to_confirm_the_new_time(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-05 10:00')->confirmed()->create();

        $moved = $this->service()->rescheduleByStaff($appointment, CarbonImmutable::parse('2026-11-05 16:00'), $reception);

        $this->assertSame(AppointmentStatus::Pending, $moved->status);
        $this->assertSame(MessageTemplateKey::Rescheduled, WhatsappMessage::sole()->template_key);
        $event = $moved->events()->sole();
        $this->assertSame('2026-11-05 10:00', $event->from_starts_at->format('Y-m-d H:i'));
    }
}
