<?php

namespace Tests\Feature\Filament;

use App\Enums\RequestedBy;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\RescheduleRequests\Pages\ListRescheduleRequests;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Los botones de una cita: los mismos, en el mismo orden y sin repetirse, en cada lugar donde aparece. */
class AppointmentButtonsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_every_place_offers_the_same_buttons_in_the_same_order(): void
    {
        $rows = $this->namesInOrder(AppointmentActions::all());

        $this->assertSame($rows, $this->namesInOrder(AppointmentActions::page()));
        // El panel de la agenda lleva la ficha del paciente como enlace bajo los datos: su menú no la repite.
        $this->assertSame(array_values(array_diff($rows, ['patientProfile'])), $this->namesInOrder(AppointmentActions::panel()));
    }

    public function test_the_appointment_page_shows_whatsapp_and_the_patient_profile_only_once(): void
    {
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->for($this->doctor)->for($this->treatment)->for($patient)->at('2026-11-03 10:00')->create();

        // El WhatsApp de la página es el botón de la cita, con su aviso ya escrito: el enlace suelto del resumen no va.
        $this->get(AppointmentResource::getUrl('view', ['record' => $appointment]))
            ->assertOk()
            ->assertSee('Llamar')
            ->assertDontSee('wa.me');

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertActionVisible('whatsapp')
            ->assertActionVisible('patientProfile')
            ->assertActionDoesNotExist('patient');

        // En su ficha, el resumen sigue trayendo el enlace para escribirle.
        $this->get(PatientResource::getUrl('view', ['record' => $patient]))->assertOk()->assertSee('wa.me');
    }

    public function test_an_appointment_that_asked_for_a_change_leads_to_its_request(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe Mamani']);
        $asked = Appointment::factory()->for($this->doctor)->for($this->treatment)->for($rosa)->at('2026-11-03 10:00')->create();
        $quiet = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-03 11:00')->create();
        $answered = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-03 12:00')->create();
        $request = $asked->rescheduleRequests()->create(['requested_by' => RequestedBy::Patient, 'status' => RescheduleRequestStatus::Pending]);
        $otherRequest = $quiet->rescheduleRequests()->create(['requested_by' => RequestedBy::Patient, 'status' => RescheduleRequestStatus::Rejected]);
        $someoneElses = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-04 10:00')->create()
            ->rescheduleRequests()->create(['requested_by' => RequestedBy::Patient, 'status' => RescheduleRequestStatus::Pending]);

        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->assertActionVisible(TestAction::make('changeRequest')->table($asked))
            ->assertActionHasUrl(TestAction::make('changeRequest')->table($asked), RescheduleRequestResource::forPatientUrl($rosa))
            // Sin pedido, o con uno que ya se contestó, no se ofrece.
            ->assertActionHidden(TestAction::make('changeRequest')->table($quiet))
            ->assertActionHidden(TestAction::make('changeRequest')->table($answered));

        Livewire::test(ViewAppointment::class, ['record' => $asked->getKey()])->assertActionVisible('changeRequest');
        Livewire::test(ViewAppointment::class, ['record' => $quiet->getKey()])->assertActionHidden('changeRequest');

        // El enlace abre los cambios pedidos que siguen pendientes, ya con el nombre del paciente en el buscador.
        parse_str((string) parse_url(RescheduleRequestResource::forPatientUrl($rosa), PHP_URL_QUERY), $query);

        Livewire::withQueryParams($query)
            ->test(ListRescheduleRequests::class)
            ->assertSet('activeTab', 'pending')
            ->assertCanSeeTableRecords([$request])
            ->assertCanNotSeeTableRecords([$otherRequest, $someoneElses]);
    }

    public function test_marking_a_no_show_says_what_happens_next(): void
    {
        $appointment = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-02 07:00')->confirmed()->create();
        $this->travelTo('2026-11-02 09:00');

        Livewire::test(ListAppointments::class, ['activeTab' => 'unclosed'])
            ->mountAction(TestAction::make('missed')->table($appointment))
            ->assertMountedActionModalSee(['Cuenta como una falta del paciente', 'tienes un día para deshacerlo'])
            ->assertMountedActionModalDontSee('¿Seguro que quieres hacer esto?');
    }

    /**
     * Los nombres de los botones como se leen: los de afuera y, donde va el menú, los de adentro.
     *
     * @param  list<Action|ActionGroup>  $actions
     * @return list<string>
     */
    private function namesInOrder(array $actions): array
    {
        return collect($actions)
            ->flatMap(fn (Action|ActionGroup $action) => $action instanceof ActionGroup ? array_keys($action->getFlatActions()) : [$action->getName()])
            ->values()
            ->all();
    }
}
