<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\MagicLink;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Phone;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** El botón WhatsApp de una cita: qué mensaje arma según cómo está la cita, y que no deje avisos sin enviar. */
class AppointmentWhatsAppTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30, 'aftercare' => null]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->reception = User::factory()->reception()->create();
        $this->actingAs($this->reception);
    }

    private function appointmentAt(string $startsAt, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
    {
        return Appointment::factory()
            ->for($this->doctor)
            ->for($this->treatment)
            ->for(Patient::factory()->state(['first_name' => 'Rosa']))
            ->at($startsAt)
            ->create(['status' => $status]);
    }

    private function chatUrl(Appointment $appointment): string
    {
        return 'https://wa.me/'.Phone::forWhatsApp($appointment->patient->account->phone);
    }

    public function test_opening_whatsapp_on_an_upcoming_visit_leaves_nothing_to_send_until_it_is_sent(): void
    {
        $appointment = $this->appointmentAt('2026-11-05 11:00');

        $page = Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->mountAction('whatsapp')
            ->assertMountedActionModalSee('WhatsApp para '.$appointment->patient->full_name)
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString("Tu próxima cita de limpieza dental es el jueves 5 de noviembre a las 11:00 am con {$this->doctor->name}", $state['body']) ?? []);

        // Solo se miró: en Avisos no queda nada ni se creó un enlace personal.
        $this->assertSame(0, WhatsappMessage::count());
        $this->assertSame(0, MagicLink::count());

        $page->callMountedAction()->assertHasNoFormErrors();

        $sent = WhatsappMessage::sole();
        $this->assertSame(MessageTemplateKey::AppointmentScheduled, $sent->template_key);
        $this->assertSame(MessageStatus::Sent, $sent->status);
        $this->assertTrue($sent->sentBy->is($this->reception));
        $this->assertSame(1, MagicLink::count());
    }

    public function test_a_visit_already_attended_opens_the_chat_instead_of_announcing_it_as_the_next_visit(): void
    {
        $attended = $this->appointmentAt('2026-10-29 11:00', AppointmentStatus::Completed);
        $inTheRoom = $this->appointmentAt('2026-11-02 09:00', AppointmentStatus::CheckedIn);
        $hourPassed = $this->appointmentAt('2026-11-02 07:00');

        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->assertActionHasUrl(TestAction::make('whatsapp')->table($attended), $this->chatUrl($attended))
            ->assertActionHasUrl(TestAction::make('whatsapp')->table($inTheRoom), $this->chatUrl($inTheRoom))
            ->assertActionHasUrl(TestAction::make('whatsapp')->table($hourPassed), $this->chatUrl($hourPassed));

        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_a_missed_visit_invites_the_patient_to_take_another_time(): void
    {
        $missed = $this->appointmentAt('2026-10-29 11:00', AppointmentStatus::NoShow);

        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->assertActionDoesNotHaveUrl(TestAction::make('whatsapp')->table($missed), $this->chatUrl($missed))
            ->mountAction(TestAction::make('whatsapp')->table($missed))
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('No pudimos verte en tu cita de limpieza dental del jueves 29 de octubre. ¿Te reservamos otro horario?', $state['body']) ?? [])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(MessageTemplateKey::MissedVisit, WhatsappMessage::sole()->template_key);
    }

    public function test_the_notice_already_waiting_for_the_visit_is_the_one_that_opens(): void
    {
        $appointment = $this->appointmentAt('2026-11-05 11:00');
        $moved = app(AppointmentService::class)->rescheduleByStaff($appointment, now()->parse('2026-11-06 16:00'), $this->reception);
        $waiting = WhatsappMessage::query()->pending()->sole();

        Livewire::test(ViewAppointment::class, ['record' => $moved->getKey()])
            ->mountAction('whatsapp')
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('cambió al viernes 6 de noviembre a las 4:00 pm', $state['body']) ?? [])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(MessageStatus::Sent, $waiting->fresh()->status);
        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_a_visit_attended_today_opens_its_aftercare_notice_while_it_waits(): void
    {
        $this->treatment->update(['aftercare' => 'No comas por dos horas.']);
        $appointment = $this->appointmentAt('2026-11-02 07:00', AppointmentStatus::Completed);
        $appointment->update(['finished_at' => now()]);
        $aftercare = app(WhatsAppOutbox::class)->queueForAppointment($appointment, MessageTemplateKey::Aftercare, extra: ['cuidados' => 'No comas por dos horas.']);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertActionDoesNotHaveUrl('whatsapp', $this->chatUrl($appointment))
            ->mountAction('whatsapp')
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('No comas por dos horas.', $state['body']) ?? [])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(MessageStatus::Sent, $aftercare->fresh()->status);

        // Ya enviado, el botón vuelve a abrir el chat.
        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertActionHasUrl('whatsapp', $this->chatUrl($appointment));
    }
}
