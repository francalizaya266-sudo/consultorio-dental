<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Doctors\Pages\ListDoctors;
use App\Filament\Resources\Doctors\RelationManagers\TreatmentsRelationManager;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Lo que el panel cuida al dar de alta, cambiar o apagar a un doctor, para que la agenda no quede con huecos escondidos. */
class DoctorSetupSafeguardsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
    }

    public function test_turning_a_doctor_off_from_the_list_asks_first_and_says_how_many_appointments_are_left(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 10:00')->create();
        // Las canceladas y las de días pasados no cuentan.
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-05 10:00')->cancelled()->create();
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-10-30 10:00')->create();
        $this->actingAs(User::factory()->admin()->create());

        $list = Livewire::test(ListDoctors::class)
            ->call('updateTableColumnState', 'is_active', (string) $doctor->id, false)
            ->assertActionMounted('deactivate')
            ->assertMountedActionModalSee(['¿Desactivar a '.$doctor->name.'?', 'Tiene 2 citas por atender']);

        // Apagar el interruptor solo pregunta: hasta confirmar, sigue atendiendo.
        $this->assertTrue($doctor->fresh()->is_active);

        $list->callMountedAction()->assertNotified($doctor->name.' ya no atiende');
        $this->assertFalse($doctor->fresh()->is_active);

        // Volver a encenderlo no necesita pregunta.
        Livewire::test(ListDoctors::class)
            ->call('updateTableColumnState', 'is_active', (string) $doctor->id, true)
            ->assertActionNotMounted('deactivate')
            ->assertNotified($doctor->name.' vuelve a atender');
        $this->assertTrue($doctor->fresh()->is_active);
    }

    public function test_the_agenda_keeps_the_pending_appointments_of_a_doctor_who_no_longer_attends(): void
    {
        $treatment = Treatment::factory()->create();
        $leaving = $this->doctorOffering($treatment);
        $gone = $this->doctorOffering($treatment);
        $staying = $this->doctorOffering($treatment);
        Appointment::factory()->for($leaving)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Lucía']))->at('2026-11-02 10:00')->create();
        $leaving->update(['is_active' => false]);
        $gone->update(['is_active' => false]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            // Sus citas pendientes siguen a la vista para atenderlas, cambiarlas o cancelarlas.
            ->assertSee([$staying->name, $leaving->name, 'Lucía', 'Ya no atiende: solo sus citas pendientes'])
            // Quien ya no atiende y no tiene citas pendientes no ocupa lugar.
            ->assertDontSee($gone->name)
            // Y a ninguno de los dos se le agenda una cita nueva.
            ->mountAction('createAppointment')
            ->assertActionDataSet(['doctor_id' => $staying->id]);
    }

    public function test_a_new_doctor_stays_off_the_web_until_their_page_is_complete(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateDoctor::class)
            ->assertSchemaStateSet(['is_public' => false, 'accepts_online_booking' => false, 'is_active' => true])
            ->fillForm(['name' => 'Dra. Ana Torres', 'slug' => 'ana-torres', 'access' => 'none'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Doctor guardado');

        $doctor = Doctor::query()->where('slug', 'ana-torres')->sole();
        $this->assertSame([false, false], [$doctor->is_public, $doctor->accepts_online_booking]);

        // En su ficha y en la lista se dice qué le falta para que se le pueda agendar.
        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertSee('Todavía no se le puede agendar: falta su horario y los tratamientos que realiza.');
        Livewire::test(ListDoctors::class)
            ->assertSee('No se le puede agendar: falta su horario y sus tratamientos.');

        $doctor->schedules()->create(['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00']);
        $doctor->treatments()->attach(Treatment::factory()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])->assertDontSee('Todavía no se le puede agendar');
        Livewire::test(ListDoctors::class)->assertDontSee('No se le puede agendar');
    }

    public function test_two_ranges_of_the_same_day_cannot_overlap(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->set('data.schedules', [
                ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                ['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '16:00'],
            ])
            ->call('save')
            ->assertHasFormErrors(['schedules'])
            ->assertSee('El lunes tiene dos tramos que se cruzan: 09:00–13:00 y 12:00–16:00. Deja uno solo o cámbiales la hora.');

        $this->assertSame(0, $doctor->schedules()->count());

        // Uno que empieza justo cuando termina el otro no se cruza, y el mismo horario en otro día tampoco.
        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->set('data.schedules', [
                ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '16:00'],
                ['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '13:00'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $doctor->schedules()->count());
    }

    public function test_shortening_the_schedule_warns_about_the_appointments_left_outside(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00'], ['15:00', '19:00']], [2])->create();
        $doctor->treatments()->attach($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Lucía', 'last_name' => 'Rojas']))->at('2026-11-03 16:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();
        // Un sobrecupo que ya estaba fuera del horario: de ese no se avisa.
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 20:00')->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->set('data.schedules', [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '13:00']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('1 cita ya dada queda fuera del horario nuevo');

        // Un cambio que no deja a nadie fuera no avisa.
        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->set('data.schedules', [['weekday' => 2, 'starts_at' => '08:00', 'ends_at' => '13:00']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified('1 cita ya dada queda fuera del horario nuevo');
    }

    public function test_the_treatments_of_a_doctor_are_added_and_removed_in_plain_words(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Endodoncia molar']);
        $doctor = Doctor::factory()->withSchedule()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(TreatmentsRelationManager::class, ['ownerRecord' => $doctor, 'pageClass' => EditDoctor::class])
            ->assertSee(['Todavía no realiza ningún tratamiento', 'Sin tratamientos no se le puede agendar. Agrega los que realiza.'])
            ->mountAction(TestAction::make('attach')->table())
            ->assertMountedActionModalSee(['Agregar tratamientos que realiza', 'Agregar'])
            ->assertMountedActionModalDontSee('Vincular')
            ->setActionData(['recordId' => [$treatment->id]])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Tratamientos agregados')
            ->mountAction(TestAction::make('detach')->table($treatment))
            ->assertMountedActionModalSee(['¿Quitar Endodoncia molar?', 'Ya no se le podrá agendar este tratamiento. Las citas ya dadas siguen igual.'])
            ->callMountedAction()
            ->assertNotified('Tratamiento quitado');

        $this->assertSame(0, $doctor->treatments()->count());
    }

    public function test_the_access_password_needs_letters_and_numbers(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateDoctor::class)
            ->fillForm(['name' => 'Dra. Ana Torres', 'slug' => 'ana-torres', 'access_email' => 'ana@consultorio.test', 'access_password' => 'solamenteletras'])
            ->call('create')
            ->assertHasFormErrors(['access_password'])
            ->fillForm(['access_password' => 'letras-y-2026'])
            ->call('create')
            ->assertHasNoFormErrors();
    }
}
