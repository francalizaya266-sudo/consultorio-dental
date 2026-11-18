<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Agenda;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** "Nueva cita" es una sola: la ventana. Se abre desde cualquier pantalla y, al agendar, se vuelve a ella. */
class NewAppointmentWindowTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30, 'price_from' => 80]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->patient = Patient::factory()->create();
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_the_old_page_address_opens_the_window_with_what_the_link_carried(): void
    {
        $this->get(route('filament.admin.resources.citas.create', ['patient' => $this->patient->id, 'date' => '2026-11-05', 'other' => 'x']))
            ->assertRedirect(AppointmentResource::newUrl(['patient' => $this->patient->id, 'date' => '2026-11-05']));

        $this->get(AppointmentResource::newUrl(['patient' => $this->patient->id, 'date' => '2026-11-05']))->assertOk();
    }

    public function test_booking_from_another_screen_goes_back_to_that_screen(): void
    {
        $patientPage = PatientResource::getUrl('view', ['record' => $this->patient]);

        $window = $this->windowOpenedFrom($patientPage, ['patient' => $this->patient->id])
            ->assertActionDataSet(['patient_id' => $this->patient->id]);

        $this->assertSame(['Volver sin agendar', $patientPage], [$this->cancelButton($window)->getLabel(), $this->cancelButton($window)->getUrl()]);

        $window
            ->setActionData(['doctor_id' => $this->doctor->id, 'treatment_id' => $this->treatment->id, 'date' => '2026-11-03', 'slot' => '10:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Cita creada. El aviso al paciente está listo en Avisos.')
            ->assertRedirect($patientPage);

        $this->assertSame('2026-11-03 10:00', $this->patient->appointments()->sole()->starts_at->format('Y-m-d H:i'));
    }

    public function test_a_booking_that_fails_stays_in_the_window(): void
    {
        Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-03 10:00')->create();

        $this->windowOpenedFrom(Dashboard::getUrl(), ['patient' => $this->patient->id])
            ->setActionData(['doctor_id' => $this->doctor->id, 'treatment_id' => $this->treatment->id, 'date' => '2026-11-03', 'manual' => true, 'manual_time' => '10:15'])
            ->callMountedAction()
            ->assertNoRedirect();

        $this->assertSame(0, $this->patient->appointments()->count());
    }

    public function test_it_only_goes_back_to_a_screen_of_the_panel_and_only_when_the_link_opened_the_window(): void
    {
        $book = ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'treatment_id' => $this->treatment->id, 'date' => '2026-11-03', 'slot' => '10:00'];

        foreach (['https://otro-sitio.test/panel/pacientes', url('/'), Agenda::getUrl(['date' => '2026-11-03'])] as $from) {
            $this->windowOpenedFrom($from, ['patient' => $this->patient->id])
                ->assertSet('returnTo', null)
                ->setActionData($book)
                ->callMountedAction()
                ->assertNoRedirect();

            Appointment::query()->delete();
        }

        // Llegó con el enlace, cerró la ventana y después tocó un espacio libre de la agenda: se queda en la agenda.
        $window = Livewire::withHeaders(['Referer' => Dashboard::getUrl()])
            ->withQueryParams(['action' => 'createAppointment'])
            ->test(Agenda::class)
            ->assertSet('returnTo', Dashboard::getUrl())
            ->mountAction('createAppointment', ['doctor' => $this->doctor->id, 'date' => '2026-11-03', 'time' => '10:00']);

        $this->assertNull($this->cancelButton($window)->getUrl());

        $window
            ->setActionData($book)
            ->callMountedAction()
            ->assertNoRedirect();
    }

    public function test_the_list_of_appointments_books_in_the_same_window_without_leaving(): void
    {
        Livewire::test(ListAppointments::class)
            ->mountAction('createAppointment')
            ->assertActionDataSet(['doctor_id' => $this->doctor->id, 'date' => '2026-11-02'])
            ->setActionData(['patient_id' => $this->patient->id, 'treatment_id' => $this->treatment->id, 'date' => '2026-11-03', 'slot' => '10:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNoRedirect()
            ->assertCanSeeTableRecords([$this->patient->appointments()->sole()]);
    }

    public function test_unticking_new_patient_brings_back_the_patient_that_was_chosen(): void
    {
        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['patient' => $this->patient->id])
            ->setActionData(['new_patient' => true])
            ->assertActionDataSet(['patient_id' => null])
            ->setActionData(['new_patient' => false])
            ->assertActionDataSet(['patient_id' => $this->patient->id]);
    }

    public function test_another_time_starts_with_the_hour_that_was_chosen_or_tapped(): void
    {
        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $this->doctor->id, 'date' => '2026-11-03', 'time' => '16:00', 'treatment' => $this->treatment->id])
            ->setActionData(['manual' => true])
            ->assertActionDataSet(['manual_time' => '16:00'])
            // Una hora ya escrita no se pisa al volver a prender "Otra hora".
            ->setActionData(['manual_time' => '19:30', 'manual' => false])
            ->setActionData(['manual' => true])
            ->assertActionDataSet(['manual_time' => '19:30']);

        // Sin tratamiento todavía, la hora que se tocó en la agenda.
        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $this->doctor->id, 'date' => '2026-11-03', 'time' => '18:20'])
            ->setActionData(['manual' => true])
            ->assertActionDataSet(['manual_time' => '18:20']);
    }

    public function test_another_time_is_chosen_with_two_taps_instead_of_typing_it(): void
    {
        $window = Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['patient' => $this->patient->id, 'doctor' => $this->doctor->id, 'date' => '2026-11-03', 'treatment' => $this->treatment->id])
            ->setActionData(['manual' => true])
            ->assertMountedActionModalSee('Toca la hora y, si no es en punto, los minutos.')
            // Sin hora no se agenda, y se dice en los botones de la hora.
            ->callMountedAction()
            ->assertHasFormErrors(['manual_hour' => 'Completa el campo hora.'])
            // Con solo tocar la hora queda en punto.
            ->setActionData(['manual_hour' => '19'])
            ->assertActionDataSet(['manual_time' => '19:00', 'manual_minute' => '00'])
            ->setActionData(['manual_minute' => '20'])
            ->assertActionDataSet(['manual_time' => '19:20'])
            ->assertMountedActionModalSee("La cita empieza a las 19:20 (7:20\u{00A0}p.\u{00A0}m.).")
            ->setActionData(['manual_hour' => '07'])
            ->assertActionDataSet(['manual_time' => '07:20'])
            ->assertMountedActionModalSee('La cita empieza a las 07:20.');

        $window->callMountedAction()->assertHasNoFormErrors();

        $this->assertSame('2026-11-03 07:20', Appointment::query()->whereBelongsTo($this->patient)->sole()->starts_at->format('Y-m-d H:i'));
    }

    public function test_the_minutes_tapped_first_wait_for_the_hour(): void
    {
        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $this->doctor->id, 'date' => '2026-11-03', 'treatment' => $this->treatment->id])
            ->setActionData(['manual' => true])
            ->setActionData(['manual_minute' => '45'])
            ->assertActionDataSet(['manual_time' => null])
            ->setActionData(['manual_hour' => '21'])
            ->assertActionDataSet(['manual_time' => '21:45']);
    }

    public function test_the_hour_that_was_already_chosen_comes_marked_in_the_buttons(): void
    {
        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $this->doctor->id, 'date' => '2026-11-03', 'time' => '16:00', 'treatment' => $this->treatment->id])
            ->setActionData(['manual' => true])
            ->assertActionDataSet(['manual_time' => '16:00', 'manual_hour' => '16', 'manual_minute' => '00'])
            // Una hora que no es de las que se ofrecen (de 06 a 22, de cinco en cinco) igual tiene su botón.
            ->setActionData(['manual_time' => '23:07'])
            ->assertActionDataSet(['manual_hour' => '23', 'manual_minute' => '07'])
            ->assertMountedActionModalSee("La cita empieza a las 23:07 (11:07\u{00A0}p.\u{00A0}m.).");
    }

    public function test_it_says_how_long_the_visit_lasts_when_it_ends_and_its_price(): void
    {
        $free = Treatment::factory()->create(['duration_minutes' => 45, 'price_from' => 0]);
        $unpriced = Treatment::factory()->create(['duration_minutes' => 90, 'price_from' => null]);
        $this->doctor->treatments()->attach([$free->id, $unpriced->id]);

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $this->doctor->id, 'date' => '2026-11-03'])
            ->assertMountedActionModalDontSee('Dura ')
            ->setActionData(['treatment_id' => $this->treatment->id])
            ->assertMountedActionModalSee("Dura 30\u{00A0}min · Precio desde S/ 80.")
            ->setActionData(['slot' => '10:00'])
            ->assertMountedActionModalSee("Dura 30\u{00A0}min: de 10:00 a 10:30 · Precio desde S/ 80.")
            ->setActionData(['manual' => true, 'manual_time' => '19:15', 'treatment_id' => $free->id])
            ->assertMountedActionModalSee("Dura 45\u{00A0}min: de 19:15 a 20:00 · Sin costo.")
            ->setActionData(['treatment_id' => $unpriced->id])
            ->assertMountedActionModalSee("Dura 1\u{00A0}h 30\u{00A0}min: de 19:15 a 20:45.");
    }

    /** El botón para cerrar la ventana sin agendar. */
    private function cancelButton(Testable $window): Action
    {
        return $window->instance()->getMountedAction()->getModalCancelAction();
    }

    /**
     * La ventana abierta por el enlace "Agendar" de otra pantalla.
     *
     * @param  array<string, int|string>  $with
     */
    private function windowOpenedFrom(string $screen, array $with): Testable
    {
        return Livewire::withHeaders(['Referer' => $screen])
            ->withQueryParams(['action' => 'createAppointment', 'actionArguments' => $with])
            ->test(Agenda::class)
            ->call('mountDefaultActions');
    }
}
