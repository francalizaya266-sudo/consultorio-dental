<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\RescheduleRequests\Pages\ListRescheduleRequests;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\Pages\ListWhatsappMessages;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\RescheduleRequestService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AgendaPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    public function test_every_role_can_open_the_agenda_pages(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->create();

        foreach ([User::factory()->admin()->create(), User::factory()->reception()->create(), $doctor->user] as $user) {
            $this->actingAs($user);

            $this->get(Dashboard::getUrl())->assertOk();
            Livewire::test(TodayStats::class)->assertSee(['Citas de hoy', 'Avisos por enviar']);
            Livewire::test(TodayAppointments::class)->assertOk();
            $this->get(AppointmentResource::getUrl('index'))->assertOk();
            $this->get(AppointmentResource::getUrl('create'))->assertOk();
            $this->get(WhatsappMessageResource::getUrl('index'))->assertOk();
            $this->get(RescheduleRequestResource::getUrl('index'))->assertOk();
        }
    }

    public function test_a_doctor_only_sees_their_own_appointments(): void
    {
        $treatment = Treatment::factory()->create();
        $mine = $this->doctorOffering($treatment);
        $other = $this->doctorOffering($treatment);
        $own = Appointment::factory()->for($mine)->for($treatment)->at('2026-11-04 10:00')->create();
        $foreign = Appointment::factory()->for($other)->for($treatment)->at('2026-11-04 10:00')->create();

        $this->actingAs($mine->user);

        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->get(AppointmentResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
        $this->get(AppointmentResource::getUrl('view', ['record' => $own]))->assertOk()->assertSee('Historial');
    }

    public function test_reception_books_a_free_slot_and_the_notice_is_prepared(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'treatment_id' => $treatment->id,
                'date' => '2026-11-03',
                'slot' => '10:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame('2026-11-03 10:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame(MessageTemplateKey::AppointmentScheduled, WhatsappMessage::sole()->template_key);
    }

    public function test_an_occupied_slot_cannot_be_booked_with_another_time(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'patient_id' => Patient::factory()->create()->id,
                'doctor_id' => $doctor->id,
                'treatment_id' => $treatment->id,
                'date' => '2026-11-03',
                'manual' => true,
                'manual_time' => '10:15',
            ])
            ->call('create')
            ->assertNotified();

        $this->assertSame(1, Appointment::count());
    }

    public function test_the_day_buttons_move_an_appointment_through_its_states(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        $widget = Livewire::test(TodayAppointments::class)->assertCanSeeTableRecords([$appointment]);

        $widget->callAction(TestAction::make('confirm')->table($appointment));
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);

        $widget->callAction(TestAction::make('arrived')->table($appointment));
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);

        $widget->callAction(TestAction::make('complete')->table($appointment));
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    public function test_reception_moves_an_appointment_from_its_page(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->confirmed()->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->callAction('move', ['doctor_id' => $doctor->id, 'date' => '2026-11-04', 'slot' => '16:00'])
            ->assertHasNoFormErrors();

        $this->assertSame('2026-11-04 16:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame(MessageTemplateKey::Rescheduled, WhatsappMessage::query()->pending()->sole()->template_key);
    }

    public function test_sending_a_notice_opens_whatsapp_and_marks_it_sent(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();
        $message = app(WhatsAppOutbox::class)->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation);
        $reception = User::factory()->reception()->create();

        $this->actingAs($reception);

        Livewire::test(ListWhatsappMessages::class)
            ->assertCanSeeTableRecords([$message])
            ->mountAction(TestAction::make('sendWhatsApp')->table($message))
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('/c/', $state['body']) ?? [])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertTrue($message->fresh()->sentBy->is($reception));
    }

    public function test_approving_a_request_from_the_panel_moves_the_appointment(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->confirmed()->create();
        $request = app(RescheduleRequestService::class)->requestByPatient($appointment, [
            CarbonImmutable::parse('2026-11-04 10:00'),
            CarbonImmutable::parse('2026-11-05 16:00'),
        ]);

        $this->actingAs($doctor->user);

        Livewire::test(ListRescheduleRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->callAction(TestAction::make('approve')->table($request), ['option_id' => $request->options[1]->id])
            ->assertHasNoFormErrors();

        $this->assertSame(RescheduleRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame('2026-11-05 16:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
    }
}
