<?php

namespace Tests\Feature\Filament;

use App\Enums\DayPeriod;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\WaitlistStatus;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\WaitlistEntries\Pages\ManageWaitlistEntries;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryResource;
use App\Filament\Widgets\WeekGaps;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WhatsappMessage;
use App\Services\Booking\WaitlistService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class WaitlistPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->doctor->update(['name' => 'Dra. Ana Torres']);
    }

    public function test_only_who_manages_the_agenda_sees_the_waitlist(): void
    {
        WaitlistEntry::factory()->for($this->treatment)->count(2)->create();

        $this->actingAs(User::factory()->reception()->create());
        $this->get(WaitlistEntryResource::getUrl('index'))->assertOk()->assertSee('Lista de espera');
        $this->assertSame('2', WaitlistEntryResource::getNavigationBadge());

        $this->actingAs($this->doctor->user);
        $this->get(WaitlistEntryResource::getUrl('index'))->assertForbidden();
    }

    public function test_reception_adds_a_patient_who_wants_an_earlier_time(): void
    {
        $reception = User::factory()->reception()->create();
        $patient = Patient::factory()->create();
        $this->actingAs($reception);

        Livewire::test(ManageWaitlistEntries::class)
            ->callAction('create', [
                'patient_id' => $patient->id,
                'treatment_id' => $this->treatment->id,
                'doctor_id' => $this->doctor->id,
                'earliest_date' => '2026-11-02',
                'latest_date' => '2026-11-20',
                'preferred_period' => DayPeriod::Morning->value,
                'notes' => 'Tiene cita el 20/11 y quiere adelantarla',
            ])
            ->assertHasNoActionErrors();

        $entry = WaitlistEntry::sole();
        $this->assertSame(WaitlistStatus::Waiting, $entry->status);
        $this->assertSame(DayPeriod::Morning, $entry->preferred_period);
        $this->assertTrue($entry->createdBy->is($reception));
    }

    public function test_reception_adds_to_the_waitlist_from_home_without_leaving_it(): void
    {
        $reception = User::factory()->reception()->create();
        $patient = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->actingAs($reception);

        Livewire::test(Dashboard::class)
            ->mountAction('waitlist')
            ->assertMountedActionModalSee(['Anotar en lista de espera', 'Cuando se libere uno que le sirva, llega la alerta para ofrecérselo.'])
            // Lo normal ya viene puesto: desde hoy y a cualquier hora.
            ->assertActionDataSet(['earliest_date' => '2026-11-02', 'preferred_period' => DayPeriod::Any])
            ->setActionData(['patient_id' => $patient->id, 'treatment_id' => $this->treatment->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Rosa Quispe quedó en la lista de espera');

        $entry = WaitlistEntry::sole();
        $this->assertSame([WaitlistStatus::Waiting, $patient->id, $this->treatment->id, null], [$entry->status, $entry->patient_id, $entry->treatment_id, $entry->doctor_id]);
        $this->assertTrue($entry->createdBy->is($reception));

        // El doctor no maneja la lista: tampoco la anota desde su inicio.
        $this->actingAs($this->doctor->user);
        Livewire::test(Dashboard::class)->assertActionHidden('waitlist');
    }

    public function test_a_patient_is_not_added_twice_for_the_same_treatment(): void
    {
        $patient = Patient::factory()->create();
        $other = Treatment::factory()->create(['name' => 'Curación con resina']);
        $this->doctor->treatments()->attach($other);
        $waiting = WaitlistEntry::factory()->for($patient)->for($this->treatment)->create();
        // Lo que ya se agendó o se retiró no estorba: puede volver a anotarse.
        WaitlistEntry::factory()->for($patient)->for($other)->removed()->create();
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageWaitlistEntries::class)
            ->callAction('create', ['patient_id' => $patient->id, 'treatment_id' => $this->treatment->id])
            ->assertHasActionErrors(['treatment_id' => 'Ya está en la lista de espera para ese tratamiento. Si cambió lo que puede, edita esa anotación.']);

        Livewire::test(ManageWaitlistEntries::class)
            ->callAction('create', ['patient_id' => $patient->id, 'treatment_id' => $other->id])
            ->assertHasNoActionErrors()
            // Editar la anotación que ya tiene no choca consigo misma.
            ->callTableAction('edit', $waiting, ['notes' => 'Solo puede sábados'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, WaitlistEntry::query()->active()->count());
        $this->assertSame('Solo puede sábados', $waiting->refresh()->notes);
    }

    public function test_the_lists_split_who_is_waiting_from_who_was_booked_or_left(): void
    {
        $waiting = WaitlistEntry::factory()->for($this->treatment)->create();
        $offered = WaitlistEntry::factory()->for($this->treatment)->offered()->create();
        $booked = WaitlistEntry::factory()->for($this->treatment)->create(['status' => WaitlistStatus::Booked]);
        $removed = WaitlistEntry::factory()->for($this->treatment)->removed()->create();
        $expired = WaitlistEntry::factory()->for($this->treatment)->create(['earliest_date' => '2026-10-20', 'latest_date' => '2026-10-30']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageWaitlistEntries::class)
            ->assertCanSeeTableRecords([$waiting, $offered])
            ->assertCanNotSeeTableRecords([$booked, $removed, $expired])
            ->set('activeTab', 'booked')
            ->assertCanSeeTableRecords([$booked])
            ->set('activeTab', 'closed')
            ->assertCanSeeTableRecords([$removed, $expired])
            ->assertSee('Venció el plazo');
    }

    public function test_an_empty_list_says_what_that_list_is_for(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageWaitlistEntries::class)
            ->assertSee(['Nadie en lista de espera', 'Anota aquí a quien quiere un horario antes'])
            ->set('activeTab', 'booked')
            ->assertSee(['Nadie de la lista se agendó todavía', 'Aquí quedan quienes pasaron de la lista de espera a una cita.'])
            ->assertDontSee('Anota aquí')
            ->set('activeTab', 'closed')
            ->assertSee(['Nadie retirado ni vencido', 'Aquí quedan quienes se retiraron de la lista'])
            ->assertDontSee('Anota aquí');
    }

    public function test_the_freed_slot_link_shows_who_can_come_that_day(): void
    {
        $canCome = WaitlistEntry::factory()->for($this->treatment)->create();
        $fromThursday = WaitlistEntry::factory()->for($this->treatment)->create(['earliest_date' => '2026-11-05']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::withQueryParams(['filters' => ['fits' => ['date' => '2026-11-04', 'doctor_id' => $this->doctor->id]]])
            ->test(ManageWaitlistEntries::class)
            ->assertCanSeeTableRecords([$canCome])
            ->assertCanNotSeeTableRecords([$fromThursday])
            ->assertSee('Pueden venir el mié. 4 nov. con Dra. Ana Torres');
    }

    public function test_offering_a_slot_opens_whatsapp_with_the_first_free_time_and_marks_it_offered(): void
    {
        $entry = WaitlistEntry::factory()
            ->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))
            ->for($this->treatment)
            ->create(['earliest_date' => '2026-11-03', 'preferred_period' => DayPeriod::Afternoon]);
        $reception = User::factory()->reception()->create();
        $this->actingAs($reception);

        Livewire::test(ManageWaitlistEntries::class)
            ->mountTableAction('offer', $entry)
            ->assertSchemaStateSet([
                'doctor_id' => $this->doctor->id,
                'date' => '2026-11-03',
                'slot' => '15:00',
                'body' => 'Hola Rosa, se liberó un horario para limpieza dental el martes 3 de noviembre a las 3:00 pm con Dra. Ana Torres. Te lo guardamos hasta las 10:00 am: ¿lo quieres? Respóndenos y te lo reservamos.',
            ])
            ->setTableActionData(['slot' => '16:30'])
            ->assertSchemaStateSet(['body' => 'Hola Rosa, se liberó un horario para limpieza dental el martes 3 de noviembre a las 4:30 pm con Dra. Ana Torres. Te lo guardamos hasta las 10:00 am: ¿lo quieres? Respóndenos y te lo reservamos.'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $entry->refresh();
        $this->assertSame(WaitlistStatus::Offered, $entry->status);
        $this->assertSame('2026-11-03 16:30', $entry->offered_starts_at->format('Y-m-d H:i'));
        // Apartado dos horas, con la atención y la esterilización.
        $this->assertSame(['2026-11-02 10:00', '2026-11-03 17:10'], [$entry->held_until->format('Y-m-d H:i'), $entry->offered_until->format('Y-m-d H:i')]);

        $message = WhatsappMessage::sole();
        $this->assertSame(MessageTemplateKey::WaitlistOffer, $message->template_key);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame($entry->patient_id, $message->patient_id);
    }

    public function test_a_slot_taken_meanwhile_is_not_offered(): void
    {
        $entry = WaitlistEntry::factory()->for($this->treatment)->create(['earliest_date' => '2026-11-03']);
        $this->actingAs(User::factory()->reception()->create());

        $page = Livewire::test(ManageWaitlistEntries::class)->mountTableAction('offer', $entry);
        Appointment::factory()->for($this->doctor)->for($this->treatment)->confirmed()->at('2026-11-03 09:00')->create();

        // Ya no aparece entre los horarios libres: hay que elegir otro.
        $page->callMountedTableAction()->assertHasTableActionErrors(['slot']);

        $this->assertSame(WaitlistStatus::Waiting, $entry->fresh()->status);
        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_booking_uses_the_offered_slot_or_moves_the_appointment_the_patient_already_has(): void
    {
        $offered = WaitlistEntry::factory()->for($this->treatment)->offered()->create([
            'offered_starts_at' => '2026-11-03 16:30',
            'offered_doctor_id' => $this->doctor->id,
        ]);
        $hasAppointment = WaitlistEntry::factory()->for($this->treatment)->create();
        $appointment = Appointment::factory()->for($hasAppointment->patient)->for($this->doctor)->for($this->treatment)->at('2026-11-20 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageWaitlistEntries::class)
            ->assertTableActionHasLabel('book', 'Agendar', $offered)
            ->assertTableActionHasUrl('book', AppointmentResource::newUrl([
                'patient' => $offered->patient_id,
                'treatment' => $this->treatment->id,
                'doctor' => $this->doctor->id,
                'date' => '2026-11-03',
                'time' => '16:30',
            ]), $offered)
            ->assertTableActionHasLabel('book', 'Adelantar cita', $hasAppointment)
            ->assertTableActionHasUrl('book', AppointmentResource::getUrl('view', ['record' => $appointment]), $hasAppointment)
            ->assertSee('Ya tiene cita el vie. 20 nov., 10:00: adelántasela');
    }

    public function test_reception_removes_someone_who_no_longer_waits(): void
    {
        $entry = WaitlistEntry::factory()->for($this->treatment)->create(['notes' => 'Prefiere sábados']);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageWaitlistEntries::class)
            ->callTableAction('remove', $entry, ['reason' => 'Se atendió en otro lado'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(WaitlistStatus::Removed, $entry->fresh()->status);
        $this->assertSame("Prefiere sábados\nRetirado: Se atendió en otro lado", $entry->fresh()->notes);
    }

    public function test_the_held_slot_shows_in_the_agenda_and_can_be_released(): void
    {
        $entry = WaitlistEntry::factory()
            ->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))
            ->for($this->treatment)
            ->create();
        app(WaitlistService::class)->markOffered($entry, $this->doctor, $this->treatment, now()->parse('2026-11-04 10:00'));
        $this->actingAs(User::factory()->reception()->create());

        Livewire::withQueryParams(['date' => '2026-11-04', 'mode' => 'day', 'doctor' => $this->doctor->id])
            ->test(Agenda::class)
            ->assertSee('Apartado para Rosa Quispe · hasta las 10:00');

        Livewire::test(ManageWaitlistEntries::class)
            ->assertSee('apartado hasta las 10:00')
            ->callTableAction('release', $entry)
            ->assertNotified('Horario liberado');

        $this->assertNull($entry->fresh()->held_until);
        $this->assertSame(WaitlistStatus::Offered, $entry->fresh()->status, 'Sigue en la lista, ya avisado.');
    }

    public function test_the_freed_slot_alert_opens_the_agenda_with_the_offer_ready(): void
    {
        $entry = WaitlistEntry::factory()
            ->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))
            ->for($this->treatment)
            ->create();
        $this->actingAs(User::factory()->reception()->create());

        $url = WaitlistEntryResource::urlForOffer($entry, $this->doctor, now()->parse('2026-11-04 10:00'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->get($url)->assertOk();

        // La página pide abrir la ventana apenas carga (wire:init).
        Livewire::withQueryParams($query)
            ->test(Agenda::class)
            ->call('mountDefaultActions')
            ->assertSet('mountedActions.0.name', 'offer')
            ->assertSchemaStateSet(['doctor_id' => $this->doctor->id, 'date' => '2026-11-04', 'slot' => '10:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('2026-11-04 10:00', $entry->fresh()->offered_starts_at->format('Y-m-d H:i'));
    }

    public function test_the_week_gaps_say_how_many_on_the_waitlist_can_come_each_day(): void
    {
        WaitlistEntry::factory()->for($this->treatment)->count(2)->create();
        WaitlistEntry::factory()->for($this->treatment)->create(['earliest_date' => '2026-11-03']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(WeekGaps::class)
            ->assertSeeInOrder(['Hoy', '2 de la lista de espera pueden venir', 'Mañana', '3 de la lista de espera pueden venir']);
    }
}
