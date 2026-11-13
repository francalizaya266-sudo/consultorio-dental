<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Un tratamiento que nadie atiende no se puede agendar: se evita al crearlo y se avisa donde haga falta. */
class TreatmentWhoAttendsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
    }

    public function test_a_new_treatment_comes_with_every_active_doctor_marked(): void
    {
        $ana = Doctor::factory()->create();
        $luis = Doctor::factory()->create();
        Doctor::factory()->create(['is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->callAction('create', data: ['name' => 'Retenedor', 'slug' => 'retenedor', 'duration_minutes' => 30, 'price_from' => 300])
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing([$ana->id, $luis->id], Treatment::query()->where('slug', 'retenedor')->sole()->doctors()->pluck('doctors.id')->all());
    }

    public function test_a_treatment_nobody_attends_is_flagged_in_the_list_and_in_its_form(): void
    {
        $doctor = Doctor::factory()->create();
        $orphan = Treatment::factory()->clinicOnly()->create(['name' => 'Carillas']);
        $attended = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $attended->doctors()->attach($doctor);
        // Uno que ya no se ofrece no necesita aviso.
        Treatment::factory()->create(['name' => 'Fuera de carta', 'is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());

        $list = Livewire::test(ManageTreatments::class)
            ->assertSeeInOrder(['Carillas', 'Nadie lo atiende: no se puede agendar. Edítalo y marca quién lo atiende.', 'Limpieza dental'])
            ->assertSeeTextInOrder(['Limpieza dental', 'Fuera de carta']);
        $this->assertSame(1, substr_count($list->html(), 'Nadie lo atiende'));

        Livewire::test(ManageTreatments::class)
            ->mountAction(TestAction::make('edit')->table($orphan))
            ->assertMountedActionModalSee('Así no se puede agendar: marca al menos un doctor.')
            ->setActionData(['doctors' => [$doctor->id]])
            ->assertMountedActionModalDontSee('Así no se puede agendar')
            ->assertMountedActionModalSee('Al agendar este tratamiento se ofrecen solo los doctores marcados.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Livewire::test(ManageTreatments::class)->assertDontSee('Nadie lo atiende');
    }

    public function test_booking_a_treatment_nobody_attends_says_where_to_fix_it(): void
    {
        $this->seed(CatalogSeeder::class);
        $attended = Treatment::factory()->create();
        $orphan = Treatment::factory()->create();
        $this->doctorOffering($attended);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment')
            ->setActionData(['treatment_id' => $attended->id])
            ->assertMountedActionModalDontSee('Ningún doctor atiende este tratamiento')
            ->setActionData(['treatment_id' => $orphan->id])
            ->assertMountedActionModalSee('Ningún doctor atiende este tratamiento. Márcalo en Configuración › Tratamientos › "¿Quién lo atiende?".', escape: false);
    }

    public function test_switching_a_treatment_off_says_what_stops_and_what_stays(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Carillas']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->call('updateTableColumnState', 'is_active', (string) $treatment->id, false)
            ->assertNotified('"Carillas" ya no se ofrece');
        $this->assertFalse($treatment->fresh()->is_active);

        Livewire::test(ManageTreatments::class)
            ->call('updateTableColumnState', 'is_active', (string) $treatment->id, true)
            ->assertNotified('"Carillas" vuelve a ofrecerse');
        $this->assertTrue($treatment->fresh()->is_active);
    }
}
