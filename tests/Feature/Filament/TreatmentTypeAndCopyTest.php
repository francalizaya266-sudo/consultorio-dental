<?php

namespace Tests\Feature\Filament;

use App\Enums\TreatmentKind;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Doctor;
use App\Models\Supply;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** El tipo de tratamiento se ve y se elige, la lista dice quién lo atiende y uno parecido se arma duplicando. */
class TreatmentTypeAndCopyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_type_chosen_in_the_form_rules_over_what_the_name_says(): void
    {
        $byName = Treatment::factory()->create(['name' => 'Profilaxis']);
        $unknown = Treatment::factory()->create(['name' => 'Carillas de porcelana']);
        $chosen = Treatment::factory()->create(['name' => 'Alineadores invisibles plus', 'kind' => TreatmentKind::Implant]);
        $evaluation = Treatment::factory()->evaluation()->create(['kind' => TreatmentKind::Cleaning]);

        $this->assertSame(TreatmentKind::Cleaning, TreatmentKind::for($byName));
        $this->assertSame(TreatmentKind::Other, TreatmentKind::for($unknown));
        // Por el nombre sería ortodoncia (control cada mes): manda lo elegido, implante (control al año).
        $this->assertSame([TreatmentKind::Implant, 12], [TreatmentKind::for($chosen), $chosen->checkupMonths()]);
        // La evaluación se marca con su interruptor y no se confunde con otro tipo.
        $this->assertSame(TreatmentKind::Evaluation, TreatmentKind::for($evaluation));

        $unknown->update(['kind' => TreatmentKind::Orthodontics]);

        $this->assertSame([1, 30], [$unknown->checkupMonths(), $unknown->nextSessionDays()]);
        // Y los llamados a control lo toman en cuenta, no solo la ficha.
        $this->assertEqualsCanonicalizing([$unknown->id], TreatmentKind::treatmentsByCheckupMonths()[1]);
        $this->assertEqualsCanonicalizing([$chosen->id], TreatmentKind::treatmentsByCheckupMonths()[12]);
    }

    public function test_the_form_shows_the_type_the_name_gives_and_lets_choose_another(): void
    {
        Doctor::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->mountAction('create')
            ->setActionData(['name' => 'Profilaxis con ultrasonido', 'duration_minutes' => 30])
            ->assertMountedActionModalSee(['Tipo de tratamiento', 'Según el nombre: Limpieza'])
            // La sesión siguiente se propone a la semana, lo usual en una limpieza.
            ->assertMountedActionModalSee('placeholder="7"', false)
            // Con otro tipo elegido, lo que se propone abajo cambia con él: en ortodoncia, al mes.
            ->setActionData(['kind' => 'orthodontics'])
            ->assertMountedActionModalSee('placeholder="30"', false)
            ->assertMountedActionModalDontSee('placeholder="7"', false)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $treatment = Treatment::query()->where('name', 'Profilaxis con ultrasonido')->sole();
        $this->assertSame([TreatmentKind::Orthodontics, 1], [$treatment->kind, $treatment->checkupMonths()]);

        // Se puede volver a dejar que lo decida el nombre.
        Livewire::test(ManageTreatments::class)
            ->mountAction(TestAction::make('edit')->table($treatment))
            ->assertActionDataSet(['kind' => 'orthodontics'])
            ->setActionData(['kind' => null])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([null, TreatmentKind::Cleaning], [$treatment->refresh()->kind, TreatmentKind::for($treatment)]);

        // Un tipo que no existe no entra.
        Livewire::test(ManageTreatments::class)
            ->callAction(TestAction::make('edit')->table($treatment), data: ['kind' => 'magia'])
            ->assertHasActionErrors(['kind']);
    }

    public function test_an_evaluation_explains_what_it_changes_and_does_not_ask_for_a_type(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->mountAction('create')
            ->assertMountedActionModalSee(['Es una evaluación', 'Márcalo solo en la cita de revisión y diagnóstico.', 'Tipo de tratamiento'])
            ->setActionData(['is_evaluation' => true])
            ->assertMountedActionModalDontSee('Tipo de tratamiento');
    }

    public function test_the_list_says_the_type_and_who_attends_each_treatment(): void
    {
        $ana = Doctor::factory()->create(['name' => 'Dra. Ana Torres', 'sort_order' => 1]);
        $luis = Doctor::factory()->create(['name' => 'Dr. Luis Ramos', 'sort_order' => 2]);
        $carla = Doctor::factory()->create(['name' => 'Dra. Carla Paz', 'sort_order' => 3]);
        $gone = Doctor::factory()->create(['name' => 'Dr. Que Ya No Atiende', 'is_active' => false]);
        $some = Treatment::factory()->create(['name' => 'Endodoncia molar', 'sessions_label' => '2']);
        $some->doctors()->attach([$luis->id, $ana->id, $gone->id]);
        $everyone = Treatment::factory()->create(['name' => 'Limpieza dental', 'kind' => TreatmentKind::Whitening]);
        $everyone->doctors()->attach([$ana->id, $luis->id, $carla->id]);
        $nobody = Treatment::factory()->create(['name' => 'Carillas']);
        $nobody->doctors()->attach($gone);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->assertSeeInOrder(['Tratamiento', 'Quién lo atiende', 'Duración de la cita'])
            // En el orden de los doctores, sin el que ya no atiende.
            ->assertTableColumnStateSet('attended_by', 'Dra. Ana Torres y Dr. Luis Ramos', $some)
            ->assertTableColumnStateSet('attended_by', 'Todos los doctores', $everyone)
            ->assertTableColumnStateSet('attended_by', null, $nobody)
            ->assertTableColumnHasDescription('name', 'Endodoncia · 2 sesiones', $some)
            // El tipo elegido, no el que diría el nombre.
            ->assertTableColumnHasDescription('name', 'Blanqueamiento · 1 sesión', $everyone);
    }

    public function test_a_treatment_is_duplicated_with_its_times_its_doctors_and_its_materials(): void
    {
        $ana = Doctor::factory()->create();
        $gone = Doctor::factory()->create(['is_active' => false]);
        $supply = Supply::factory()->create();
        $source = Treatment::factory()->create([
            'name' => 'Endodoncia molar', 'kind' => TreatmentKind::RootCanal, 'duration_minutes' => 60, 'buffer_minutes' => 20, 'price_from' => 450,
            'checkup_months' => 8, 'aftercare' => 'No mastiques de ese lado.', 'summary' => 'Salvamos tu muela.', 'seo_title' => 'Endodoncia en Juliaca',
            'is_public' => true, 'online_bookable' => true,
        ]);
        $source->doctors()->attach([$ana->id => ['duration_minutes' => 90], $gone->id => ['duration_minutes' => 45]]);
        $source->supplies()->attach($supply, ['quantity' => 2]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->mountAction(TestAction::make('duplicate')->table($source))
            ->assertActionDataSet(['name' => 'Endodoncia molar (copia)'])
            ->setActionData(['name' => 'Endodoncia premolar'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Tratamiento duplicado');

        $copy = Treatment::query()->where('name', 'Endodoncia premolar')->sole();
        $this->assertSame(
            [TreatmentKind::RootCanal, 60, 20, '450.00', 8, 'No mastiques de ese lado.', true],
            [$copy->kind, $copy->duration_minutes, $copy->buffer_minutes, $copy->price_from, $copy->checkup_months, $copy->aftercare, $copy->is_active],
        );
        // Su dirección sale del nombre nuevo, queda solo para el consultorio y lo de la página del otro no se copia.
        $this->assertSame(['endodoncia-premolar', false, false, null, null], [$copy->slug, $copy->is_public, $copy->online_bookable, $copy->summary, $copy->seo_title]);
        // Los doctores que atienden, con su duración propia; el que ya no atiende no pasa.
        $this->assertSame([$ana->id => 90], $copy->doctors->mapWithKeys(fn (Doctor $doctor) => [$doctor->id => (int) $doctor->pivot->duration_minutes])->all());
        $this->assertSame([$supply->id => 2.0], $copy->supplies->mapWithKeys(fn (Supply $used) => [$used->id => (float) $used->pivot->quantity])->all());
        // El original no cambió.
        $this->assertSame(['Endodoncia molar', true, 'Salvamos tu muela.'], [$source->refresh()->name, $source->is_public, $source->summary]);

        // El nombre es obligatorio, y uno borrado no se duplica.
        Livewire::test(ManageTreatments::class)
            ->callAction(TestAction::make('duplicate')->table($source), data: ['name' => ''])
            ->assertHasActionErrors(['name']);

        $copy->delete();

        Livewire::test(ManageTreatments::class)
            ->filterTable('trashed', false)
            ->assertActionHidden(TestAction::make('duplicate')->table($copy));
    }
}
