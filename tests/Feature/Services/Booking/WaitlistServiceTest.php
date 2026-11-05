<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\DayPeriod;
use App\Enums\MessageTemplateKey;
use App\Enums\WaitlistStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\WaitlistService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class WaitlistServiceTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
    }

    private function service(): WaitlistService
    {
        return app(WaitlistService::class);
    }

    public function test_booking_the_patient_takes_them_off_the_list_for_that_treatment(): void
    {
        $cleaning = Treatment::factory()->create(['duration_minutes' => 30]);
        $crown = Treatment::factory()->create(['duration_minutes' => 60]);
        $doctor = $this->doctorOffering($cleaning, $crown);
        $patient = Patient::factory()->create();
        $forCleaning = WaitlistEntry::factory()->for($patient)->for($cleaning)->create();
        $forCrown = WaitlistEntry::factory()->for($patient)->for($crown)->create();
        $anything = WaitlistEntry::factory()->for($patient)->create(['treatment_id' => null]);

        $appointment = app(AppointmentService::class)->bookFromStaff(User::factory()->reception()->create(), $patient, $doctor, $cleaning, CarbonImmutable::parse('2026-11-04 10:00'));

        $this->assertSame(WaitlistStatus::Booked, $forCleaning->fresh()->status);
        $this->assertTrue($forCleaning->fresh()->appointment->is($appointment));
        $this->assertSame(WaitlistStatus::Booked, $anything->fresh()->status, 'Quien esperaba cualquier tratamiento ya tiene su cita.');
        $this->assertSame(WaitlistStatus::Waiting, $forCrown->fresh()->status);
    }

    public function test_moving_the_appointment_earlier_settles_the_entry_but_moving_it_later_does_not(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $reception = User::factory()->reception()->create();
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-20 10:00')->create();
        $entry = WaitlistEntry::factory()->for($appointment->patient)->for($treatment)->create();

        app(AppointmentService::class)->rescheduleByStaff($appointment, CarbonImmutable::parse('2026-11-25 10:00'), $reception);
        $this->assertSame(WaitlistStatus::Waiting, $entry->fresh()->status);

        app(AppointmentService::class)->rescheduleByStaff($appointment->fresh(), CarbonImmutable::parse('2026-11-04 10:00'), $reception);
        $this->assertSame(WaitlistStatus::Booked, $entry->fresh()->status);
        $this->assertSame($appointment->id, $entry->fresh()->appointment_id);
    }

    public function test_a_freed_slot_goes_to_whoever_can_come_then_and_fits_in_it(): void
    {
        $short = Treatment::factory()->create(['duration_minutes' => 30]);
        $long = Treatment::factory()->create(['duration_minutes' => 120]);
        $doctor = $this->doctorOffering($short, $long);
        $other = $this->doctorOffering($short);
        // Hay otra cita a las 16:00: en el hueco de las 15:00 solo entra lo corto.
        Appointment::factory()->for($doctor)->for($short)->confirmed()->at('2026-11-04 16:00', 60)->create();
        $freed = CarbonImmutable::parse('2026-11-04 15:00');

        $fits = WaitlistEntry::factory()->for($short)->create();
        $afternoon = WaitlistEntry::factory()->for($short)->create(['preferred_period' => DayPeriod::Afternoon, 'doctor_id' => $doctor->id]);
        WaitlistEntry::factory()->for($short)->create(['preferred_period' => DayPeriod::Morning]);
        WaitlistEntry::factory()->for($short)->create(['latest_date' => '2026-11-03']);
        WaitlistEntry::factory()->for($short)->create(['earliest_date' => '2026-11-05']);
        WaitlistEntry::factory()->for($short)->create(['doctor_id' => $other->id]);
        WaitlistEntry::factory()->for($long)->create();
        WaitlistEntry::factory()->for($short)->removed()->create();
        $own = WaitlistEntry::factory()->for($short)->create();

        $candidates = $this->service()->candidatesFor($doctor, $freed, exceptPatientId: $own->patient_id);

        $this->assertEqualsCanonicalizing([$fits->id, $afternoon->id], $candidates->modelKeys());
    }

    public function test_the_first_slot_offered_is_the_earliest_that_suits_the_patient(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $entry = WaitlistEntry::factory()->for($treatment)->create([
            'earliest_date' => '2026-11-04',
            'preferred_period' => DayPeriod::Afternoon,
        ]);
        Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-04 15:00', 60)->create();

        $first = $this->service()->firstSlot($entry);

        $this->assertTrue($first['doctor']->is($doctor));
        $this->assertSame('2026-11-04 16:10', $first['slot']->startsAt->format('Y-m-d H:i'), 'Después de la cita y su esterilización.');
    }

    public function test_there_is_no_slot_once_the_patient_deadline_has_no_room(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = Doctor::factory()->withSchedule(weekdays: [6])->create();
        $doctor->treatments()->attach($treatment);
        $entry = WaitlistEntry::factory()->for($treatment)->create(['latest_date' => '2026-11-06']);

        $this->assertNull($this->service()->firstSlot($entry), 'El doctor recién atiende el sábado 7.');
    }

    public function test_the_offer_message_has_the_slot_and_marks_the_entry_as_offered(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $doctor->update(['name' => 'Dra. Ana Torres']);
        $entry = WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Rosa']))->for($treatment)->create();
        $startsAt = CarbonImmutable::parse('2026-11-04 10:30');

        $message = $this->service()->offerDraft($entry, $doctor, $treatment, $startsAt);
        $this->service()->markOffered($entry, $doctor, $startsAt);

        $this->assertSame(MessageTemplateKey::WaitlistOffer, $message->template_key);
        $this->assertFalse($message->exists, 'Se guarda recién al abrir WhatsApp.');
        $this->assertStringContainsString('Hola Rosa, se liberó un horario para limpieza dental el miércoles 4 de noviembre a las 10:30 am con Dra. Ana Torres.', app(WhatsAppOutbox::class)->render($message));
        $this->assertSame(WaitlistStatus::Offered, $entry->fresh()->status);
        $this->assertSame('2026-11-04 10:30', $entry->fresh()->offered_starts_at->format('Y-m-d H:i'));
        $this->assertTrue($entry->fresh()->offeredDoctor->is($doctor));
    }

    public function test_reception_is_told_whom_to_offer_the_slot_when_a_patient_cancels(): void
    {
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-04 10:00')->create();
        WaitlistEntry::factory()->for($treatment)->create();

        app(AppointmentService::class)->cancelByPatient($appointment);

        $notification = $reception->notifications()->sole();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertStringContainsString('Hay 1 paciente en lista de espera que puede venir a esa hora.', $notification->data['body']);
        $this->assertSame('Ofrecer el horario', $notification->data['actions'][0]['label']);
        $this->assertStringContainsString('lista-de-espera', $notification->data['actions'][0]['url']);
        $this->assertStringContainsString('2026-11-04', urldecode($notification->data['actions'][0]['url']));
    }

    public function test_reception_hears_about_the_waitlist_when_it_cancels_an_appointment_itself(): void
    {
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-04 10:00')->create();

        app(AppointmentService::class)->cancelByStaff($appointment, $reception, notifyPatient: false);
        $this->assertSame(0, $reception->notifications()->count(), 'Sin nadie en espera, no hay nada que avisar.');

        $second = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-05 10:00')->create();
        WaitlistEntry::factory()->for($treatment)->create();

        app(AppointmentService::class)->cancelByStaff($second, $reception, notifyPatient: false);
        $this->assertSame('Se liberó un horario', $reception->notifications()->sole()->data['title']);
    }
}
