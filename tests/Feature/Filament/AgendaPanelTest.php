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
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\RescheduleRequests\Pages\ListRescheduleRequests;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\Pages\ListWhatsappMessages;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Models\Appointment;
use App\Models\MagicLink;
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
use Illuminate\Support\Once;
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
            Livewire::test(TodayStats::class)->assertSee(['Citas de hoy', 'Por confirmar']);
            Livewire::test(TodayAppointments::class)->assertOk();
            $this->get(AppointmentResource::getUrl('index'))->assertOk();
            $this->get(AppointmentResource::getUrl('create'))->assertOk();
            $this->get(WhatsappMessageResource::getUrl('index'))->assertOk();
            $this->get(RescheduleRequestResource::getUrl('index'))->assertOk();
        }
    }

    public function test_the_doctor_column_and_filter_only_show_with_more_than_one_doctor(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)->assertTableColumnHidden('doctor.name');
        Livewire::test(ListAppointments::class)->assertTableColumnHidden('doctor.name')->assertTableFilterHidden('doctor_id');

        $this->doctorOffering($treatment);
        // Cada pedido cuenta los doctores una sola vez.
        Once::flush();

        Livewire::test(TodayAppointments::class)->assertTableColumnVisible('doctor.name');
        Livewire::test(ListAppointments::class)->assertTableColumnVisible('doctor.name')->assertTableFilterVisible('doctor_id');
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

        // Con el paciente en sala, cerrarla le toca a su doctor: para recepción es la excepción, en "Más".
        $widget->assertActionHidden(TestAction::make('complete')->table($appointment))
            ->callAction(TestAction::make('closeWithoutDoctor')->table($appointment));
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);

        Livewire::test(TodayStats::class)->assertSee('1 atendida')->assertDontSee('1 atendidas');
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

    public function test_an_appointment_leads_back_to_the_patient_and_to_their_other_visits(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create(['first_name' => 'Ana Paula', 'last_name' => 'Acosta Gaona']);
        $earlier = Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-07-24 15:00')->create(['status' => AppointmentStatus::Completed]);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-03 10:00')->confirmed()->create();
        $later = Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-12-01 09:00')->create();
        $someoneElse = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-05 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        // Arriba, la ruta vuelve a la ficha; al costado, sus otras citas con esta marcada.
        $this->get(AppointmentResource::getUrl('view', ['record' => $appointment]))
            ->assertOk()
            ->assertSeeInOrder(['Pacientes', 'Ana Paula Acosta Gaona', 'Cita del 03/11/2026'])
            ->assertSee(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertSeeInOrder(['Sus citas', 'Mar. 01/12/2026 · 09:00', 'Esta cita', 'Vie. 24/07/2026 · 15:00', 'Ver su ficha'])
            ->assertSee(AppointmentResource::getUrl('view', ['record' => $earlier]))
            ->assertSee(AppointmentResource::getUrl('view', ['record' => $later]))
            ->assertDontSee(AppointmentResource::getUrl('view', ['record' => $someoneElse]));

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertActionVisible('patient')
            ->assertActionHidden(TestAction::make('patientProfile')->table($appointment));

        // Desde la lista de citas, el menú de cada una también lleva a la ficha.
        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->assertActionVisible(TestAction::make('patientProfile')->table($appointment));
    }

    public function test_sending_a_notice_opens_whatsapp_and_marks_it_sent(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();
        $message = app(WhatsAppOutbox::class)->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation);
        $reception = User::factory()->reception()->create();

        $this->actingAs($reception);

        $list = Livewire::test(ListWhatsappMessages::class)
            ->assertCanSeeTableRecords([$message])
            ->mountAction(TestAction::make('sendWhatsApp')->table($message))
            // Al revisarlo todavía no hay enlace personal: se crea recién al abrir WhatsApp.
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('{enlace}', $state['body']) ?? []);
        $this->assertSame(0, MagicLink::count());

        $list->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Aviso para '.$appointment->patient->full_name.' abierto en WhatsApp');

        $this->assertSame(1, MagicLink::count());
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertTrue($message->fresh()->sentBy->is($reception));

        // Si al final no se envió, desde Enviados vuelve a "Por enviar".
        $list->set('activeTab', 'sent')->callAction(TestAction::make('notSent')->table($message));
        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);
        $this->assertNull($message->fresh()->sent_at);
    }

    public function test_cancelling_a_notice_leaves_no_personal_link_behind(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();
        $message = app(WhatsAppOutbox::class)->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListWhatsappMessages::class)
            ->mountAction(TestAction::make('sendWhatsApp')->table($message))
            ->assertMountedActionModalSeeHtml('WhatsApp para '.e($appointment->patient->full_name));

        $this->assertSame(0, MagicLink::count());
        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);
    }

    public function test_notices_to_send_start_with_the_nearest_appointment_and_show_their_text(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $outbox = app(WhatsAppOutbox::class);
        $thursday = $outbox->queueForAppointment(Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->create(['first_name' => 'Rosa']))->at('2026-11-05 10:00')->create(), MessageTemplateKey::BookingConfirmation);
        $this->travel(5)->minutes();
        $tuesday = $outbox->queueForAppointment(Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->create(['first_name' => 'Pedro']))->at('2026-11-03 09:00')->create(), MessageTemplateKey::BookingConfirmation);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListWhatsappMessages::class)
            // Pedro se preparó después, pero su cita es antes: va primero.
            ->assertCanSeeTableRecords([$tuesday, $thursday], inOrder: true)
            ->assertSee('Hola Pedro 👋')
            ->assertSee('Tu cita de limpieza dental quedó reservada')
            // En Enviados y Todos, el último preparado arriba.
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$tuesday, $thursday], inOrder: true);
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
