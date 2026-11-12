<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** El botón "Deshacer" en la agenda, en la lista de citas y en la página de la cita. */
class UndoAppointmentStepTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    public function test_an_unclosed_visit_in_the_day_list_offers_that_the_patient_came_next_to_no_show(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $ended = Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Lucía']))->confirmed()->at('2026-11-02 09:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Bruno']))->confirmed()->at('2026-11-02 16:00')->create();
        $this->travelTo('2026-11-02 11:00');

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->set('mode', 'list')
            // La que ya pasó: los tres botones. La que aún no llega: solo el paso que sigue.
            ->assertSeeHtmlInOrder(['Lucía', 'Sin cerrar', 'No asistió', 'Llegó', 'Atendido', 'Ahora · 11:00', 'Bruno', 'Llegó'])
            ->assertActionVisible(TestAction::make('listMissed')->arguments(['id' => $ended->id]))
            ->assertActionVisible(TestAction::make('listArrived')->arguments(['id' => $ended->id]))
            ->assertActionVisible(TestAction::make('listComplete')->arguments(['id' => $ended->id]));
    }

    public function test_a_no_show_tapped_by_mistake_in_the_day_list_is_undone_from_the_same_card(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-02 09:00')->create();
        $this->travelTo('2026-11-02 11:00');

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->set('mode', 'list')
            ->callAction(TestAction::make('listMissed')->arguments(['id' => $appointment->id]))
            ->assertNotified('Marcada como inasistencia')
            ->assertSee('Deshacer «No asistió»')
            ->mountAction(TestAction::make('listUndo')->arguments(['id' => $appointment->id]))
            ->assertMountedActionModalSee(['¿Deshacer «No asistió»?', 'vuelve a quedar confirmada'])
            ->callMountedAction()
            ->assertNotified('Listo, la cita volvió a como estaba');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_the_panel_of_a_visit_undoes_an_arrival_from_its_more_menu(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-02 09:00')->create();
        $reception = User::factory()->reception()->create();
        app(AppointmentService::class)->markArrived($appointment, $reception);

        $this->actingAs($reception);

        Livewire::test(Agenda::class)
            ->mountAction(TestAction::make('appointment')->arguments(['id' => $appointment->id]))
            // A la vista queda el paso del día; deshacer el "Llegó" va en "Más".
            ->assertActionHidden('undo')
            ->assertActionVisible('undoStep')
            ->callAction('undoStep')
            ->assertNotified('Listo, la cita volvió a como estaba');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_a_cancelled_visit_is_restored_from_its_page_for_a_day(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-04 09:00')->create();
        $reception = User::factory()->reception()->create();
        app(AppointmentService::class)->cancelByStaff($appointment, $reception, notifyPatient: false);

        $this->actingAs($reception);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertActionVisible('undo')
            ->mountAction('undo')
            ->assertMountedActionModalSee(['¿Deshacer cancelación?', 'si ese horario sigue libre'])
            ->callMountedAction()
            ->assertNotified('Listo, la cita volvió a como estaba');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);

        // Pasado un día, ya no se ofrece.
        app(AppointmentService::class)->cancelByStaff($appointment->fresh(), $reception, notifyPatient: false);
        $this->travel(25)->hours();

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])->assertActionHidden('undo');
    }

    public function test_the_appointments_list_undoes_a_confirmation_from_the_row_menu(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 09:00')->create();
        $justBooked = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-04 10:00')->create(['confirmed_via' => 'reception']);
        $reception = User::factory()->reception()->create();
        $this->travel(10)->minutes();
        app(AppointmentService::class)->confirmByStaff($appointment, $reception);

        $this->actingAs($reception);

        Livewire::test(ListAppointments::class, ['activeTab' => 'upcoming'])
            // La que nació confirmada al agendarla no tiene una confirmación que deshacer.
            ->assertActionHidden(TestAction::make('undoStep')->table($justBooked))
            ->callAction(TestAction::make('undoStep')->table($appointment))
            ->assertNotified('Listo, la cita volvió a como estaba');

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_the_history_of_the_visit_says_what_was_undone(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-02 09:00')->create();
        $reception = User::factory()->reception()->create();
        $this->actingAs($reception);
        $this->travelTo('2026-11-02 11:00');
        app(AppointmentService::class)->markNoShow($appointment, $reception);
        app(AppointmentService::class)->undoLastStep($appointment, $reception);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSeeInOrder(['No asistió', 'Se deshizo: no asistió']);
    }
}
