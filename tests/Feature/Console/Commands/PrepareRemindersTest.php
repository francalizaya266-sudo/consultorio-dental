<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** El recordatorio de cada cita: el día anterior en que se atiende, una sola vez y sin repetir lo que ya se le dijo. */
class PrepareRemindersTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_prepares_one_reminder_per_appointment_tomorrow_from_the_morning(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $tomorrow = Appointment::factory()->at('2026-11-03 10:00')->create();
        Appointment::factory()->at('2026-11-03 11:00')->cancelled()->create();
        Appointment::factory()->at('2026-11-04 10:00')->create();

        $this->travelTo('2026-11-02 08:00');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(0, WhatsappMessage::count());

        // Aunque la tarea no corra justo a las 9:00, la siguiente pasada lo prepara. Y no se repite.
        $this->travelTo('2026-11-02 11:40');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        $reminder = WhatsappMessage::sole();
        $this->assertSame(MessageTemplateKey::Reminder, $reminder->template_key);
        $this->assertTrue($reminder->appointment->is($tomorrow));
        $this->assertStringContainsString('tu cita de mañana martes 3 de noviembre a las 10:00 am', app(WhatsAppOutbox::class)->draft($reminder));
    }

    public function test_a_monday_appointment_is_reminded_on_saturday_because_nobody_is_there_on_sunday(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $doctor = Doctor::factory()->withSchedule()->create();
        $monday = Appointment::factory()->for($doctor)->at('2026-11-09 10:00')->create();

        $this->travelTo('2026-11-06 12:00');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(0, WhatsappMessage::count());

        $this->travelTo('2026-11-07 09:05');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        $reminder = WhatsappMessage::sole();
        $this->assertTrue($reminder->appointment->is($monday));
        // El texto dice el día, no "mañana": se arma según cuándo se envía.
        $this->assertStringContainsString('tu cita del lunes 9 de noviembre a las 10:00 am', app(WhatsAppOutbox::class)->draft($reminder));

        $this->travelTo('2026-11-08 09:05');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(1, WhatsappMessage::count());
        $this->assertStringContainsString('tu cita de mañana lunes 9 de noviembre', app(WhatsAppOutbox::class)->draft($reminder));
    }

    public function test_with_the_whatsapp_api_the_reminder_goes_out_the_day_before_even_on_sunday(): void
    {
        config(['consultorio.whatsapp.driver' => 'cloud_api']);
        $this->travelTo('2026-10-26 10:00');
        $doctor = Doctor::factory()->withSchedule()->create();
        Appointment::factory()->for($doctor)->at('2026-11-09 10:00')->create();

        $this->travelTo('2026-11-07 09:05');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(0, WhatsappMessage::count());

        $this->travelTo('2026-11-08 09:05');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_an_appointment_booked_or_moved_that_same_day_gets_no_reminder_on_top_of_its_notice(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $doctor = $this->doctorOffering($treatment = Treatment::factory()->create());
        $moved = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-05 10:00')->create();

        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        // Recepción la adelanta a mañana: el aviso del cambio ya le dice el día y la hora.
        app(AppointmentService::class)->rescheduleByStaff($moved, $moved->starts_at->setDate(2026, 11, 3), $reception);
        // Y otra que se agenda hoy para mañana.
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 16:00')->create();

        $this->travelTo('2026-11-02 11:00');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        $this->assertSame([MessageTemplateKey::Rescheduled], WhatsappMessage::pluck('template_key')->all());
    }

    public function test_an_appointment_moved_to_another_day_is_reminded_again_for_its_new_day(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $doctor = $this->doctorOffering($treatment = Treatment::factory()->create());
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();
        $reception = User::factory()->reception()->create();

        $this->travelTo('2026-11-02 09:30');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        app(WhatsAppOutbox::class)->markSent(WhatsappMessage::sole(), $reception);

        // El paciente pide pasarla al viernes: el recordatorio del lunes ya no vale para ese día.
        app(AppointmentService::class)->rescheduleByStaff($appointment, $appointment->starts_at->setDate(2026, 11, 6), $reception);

        $this->travelTo('2026-11-05 09:30');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        $reminders = WhatsappMessage::where('template_key', MessageTemplateKey::Reminder)->oldest('id')->get();
        $this->assertSame([MessageStatus::Sent, MessageStatus::Pending], $reminders->pluck('status')->all());
        $this->assertStringContainsString('viernes 6 de noviembre', app(WhatsAppOutbox::class)->draft($reminders->last()));
    }

    public function test_notices_nobody_sent_leave_the_tray_once_the_appointment_has_passed(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $appointment = Appointment::factory()->at('2026-11-03 10:00')->create();
        $outbox = app(WhatsAppOutbox::class);
        $confirmation = $outbox->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation);
        $aftercare = $outbox->queueForAppointment($appointment, MessageTemplateKey::Aftercare, extra: ['cuidados' => 'No comas por dos horas.']);

        $this->travelTo('2026-11-02 09:30');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(3, WhatsappMessage::due()->count());

        $this->travelTo('2026-11-03 10:30');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        // Solo quedan los cuidados para después de la atención.
        $this->assertSame(MessageStatus::Cancelled, $confirmation->fresh()->status);
        $this->assertTrue(WhatsappMessage::due()->sole()->is($aftercare));
    }
}
