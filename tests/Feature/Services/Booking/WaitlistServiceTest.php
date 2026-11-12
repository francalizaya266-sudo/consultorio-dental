<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\DayPeriod;
use App\Enums\MessageTemplateKey;
use App\Enums\WaitlistStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Agenda\AvailabilityService;
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
        $this->service()->markOffered($entry, $doctor, $treatment, $startsAt);

        $this->assertSame(MessageTemplateKey::WaitlistOffer, $message->template_key);
        $this->assertFalse($message->exists, 'Se guarda recién al abrir WhatsApp.');
        $this->assertStringContainsString('Hola Rosa, se liberó un horario para limpieza dental el miércoles 4 de noviembre a las 10:30 am con Dra. Ana Torres. Te lo guardamos hasta las 10:00 am', app(WhatsAppOutbox::class)->render($message));
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
        // Rosa espera desde antes: se le propone a ella.
        $rosa = WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))->for($treatment)->create(['created_at' => now()->subDays(3)]);
        WaitlistEntry::factory()->for($treatment)->create();

        app(AppointmentService::class)->cancelByPatient($appointment);

        $notification = $reception->notifications()->sole();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertStringContainsString('Rosa Quispe, de la lista de espera, puede venir a esa hora (y 1 persona más).', $notification->data['body']);
        // El botón abre la agenda de ese día con el mensaje para Rosa listo.
        $this->assertSame('Ofrecérselo a Rosa', $notification->data['actions'][0]['label']);
        $url = urldecode($notification->data['actions'][0]['url']);
        $this->assertStringContainsString('agenda?', $url);
        $this->assertStringContainsString('action=offer', $url);
        $this->assertStringContainsString("actionArguments[entry]={$rosa->id}", $url);
        $this->assertStringContainsString('actionArguments[time]=10:00', $url);
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

    public function test_an_offered_slot_is_held_for_that_patient_while_they_answer(): void
    {
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $rosa = WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))->for($treatment)->create();
        $luis = WaitlistEntry::factory()->for($treatment)->create();
        $startsAt = CarbonImmutable::parse('2026-11-04 10:00');

        $this->service()->markOffered($rosa, $doctor, $treatment, $startsAt);

        // Nadie más lo ve libre: ni Luis, ni la web, ni otra alerta; para Rosa sigue disponible.
        $times = fn (WaitlistEntry $entry) => $this->service()->slotsFor($entry, $doctor, $treatment, $startsAt)->map(fn ($slot) => $slot->label())->all();
        $this->assertNotContains('10:00', $times($luis));
        $this->assertContains('10:00', $times($rosa->fresh()));
        $this->assertFalse(app(AvailabilityService::class)->isFree($doctor, $startsAt, 30, 10));
        $this->assertCount(0, $this->service()->candidatesFor($doctor, $startsAt));

        // Recepción no se lo da a otro sin liberarlo primero.
        try {
            app(AppointmentService::class)->bookFromStaff($reception, $luis->patient, $doctor, $treatment, $startsAt);
            $this->fail('Se agendó encima de un horario apartado.');
        } catch (BookingException $e) {
            $this->assertSame('Ese horario está apartado para Rosa Quispe (lista de espera) hasta las 10:00, mientras responde. Si ya no lo quiere, libéralo en la lista de espera.', $e->getMessage());
        }

        // Rosa dice que sí: se le agenda en su horario y sale de la lista.
        app(AppointmentService::class)->bookFromStaff($reception, $rosa->patient, $doctor, $treatment, $startsAt);
        $this->assertSame(WaitlistStatus::Booked, $rosa->fresh()->status);
    }

    public function test_a_slot_offered_for_very_soon_is_still_held_long_enough_to_answer(): void
    {
        $this->travelTo('2026-11-02 09:00');
        $heldUntil = fn (string $startsAt) => $this->service()->holdEndsAt(CarbonImmutable::parse($startsAt))->format('H:i');

        // Lo de siempre: dos horas, o hasta media hora antes de la cita.
        $this->assertSame(['11:00', '10:00', '09:20'], [$heldUntil('2026-11-02 15:00'), $heldUntil('2026-11-02 10:30'), $heldUntil('2026-11-02 09:50')]);
        // Muy pronto: al menos un cuarto de hora para responder, sin pasar de la hora en que empieza.
        $this->assertSame(['09:15', '09:15', '09:15', '09:10'], [$heldUntil('2026-11-02 09:40'), $heldUntil('2026-11-02 09:31'), $heldUntil('2026-11-02 09:20'), $heldUntil('2026-11-02 09:10')]);
    }

    public function test_the_offer_for_a_slot_in_half_an_hour_tells_the_patient_a_time_they_can_still_meet(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $rosa = WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Rosa']))->for($treatment)->create();
        $this->travelTo('2026-11-02 09:00');
        $startsAt = CarbonImmutable::parse('2026-11-02 09:35');

        $message = $this->service()->offerDraft($rosa, $doctor, $treatment, $startsAt);
        $this->service()->markOffered($rosa, $doctor, $treatment, $startsAt);

        // Antes le decía "hasta las 9:05": cinco minutos después el horario ya se ofrecía a otro.
        $this->assertStringContainsString('Te lo guardamos hasta las 9:15 am', app(WhatsAppOutbox::class)->render($message));
        $this->assertSame('09:15', $rosa->fresh()->held_until->format('H:i'));
    }

    public function test_an_unanswered_offer_frees_the_slot_and_suggests_the_next_person(): void
    {
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $rosa = WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))->for($treatment)->create(['created_at' => now()->subDays(3)]);
        WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Luis', 'last_name' => 'Soto']))->for($treatment)->create();
        $startsAt = CarbonImmutable::parse('2026-11-04 10:00');
        $this->service()->markOffered($rosa, $doctor, $treatment, $startsAt);

        $this->artisan('lista-espera:liberar-apartados')->assertSuccessful();
        $this->assertSame(0, $reception->notifications()->count(), 'Todavía está a tiempo de responder.');

        $this->travel(2)->hours();
        $this->artisan('lista-espera:liberar-apartados')->assertSuccessful();

        $this->assertNull($rosa->fresh()->held_until);
        $this->assertTrue(app(AvailabilityService::class)->isFree($doctor, $startsAt, 30, 10));
        $notification = $reception->notifications()->sole();
        $this->assertSame('Rosa Quispe no respondió al horario ofrecido', $notification->data['title']);
        $this->assertStringContainsString('ya no está apartado. Luis Soto, de la lista de espera, puede venir a esa hora.', $notification->data['body']);
        $this->assertSame('Ofrecérselo a Luis', $notification->data['actions'][0]['label']);
    }

    public function test_moving_an_appointment_frees_its_old_time_for_the_waitlist(): void
    {
        $reception = User::factory()->reception()->create();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-04 10:00')->create();
        WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))->for($treatment)->create();

        app(AppointmentService::class)->rescheduleByStaff($appointment, CarbonImmutable::parse('2026-11-05 10:00'), $reception);

        $notification = $reception->notifications()->sole();
        $this->assertSame('Se liberó un horario', $notification->data['title']);
        $this->assertStringContainsString('Rosa Quispe, de la lista de espera, puede venir a esa hora.', $notification->data['body']);
    }
}
