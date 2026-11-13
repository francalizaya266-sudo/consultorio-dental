<?php

namespace Tests\Feature\Filament;

use App\Enums\WebPresence;
use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Doctors\Pages\ListDoctors;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** "Dónde aparece": una sola pregunta para el doctor y el tratamiento, en vez de dos interruptores que se pisaban. */
class WhereItAppearsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{bool, bool, WebPresence}> */
    public static function savedFlags(): array
    {
        return [
            'ni en la página ni en la reserva' => [false, false, WebPresence::Clinic],
            'solo en la página' => [true, false, WebPresence::Web],
            'en la página y en la reserva' => [true, true, WebPresence::Booking],
            'en la reserva sin salir en la página' => [false, true, WebPresence::BookingOnly],
        ];
    }

    #[DataProvider('savedFlags')]
    public function test_what_was_saved_with_the_two_switches_reads_as_one_level(bool $public, bool $bookable, WebPresence $expected): void
    {
        $treatment = Treatment::factory()->create(['is_public' => $public, 'online_bookable' => $bookable]);
        $doctor = Doctor::factory()->create(['is_public' => $public, 'accepts_online_booking' => $bookable]);

        $this->assertSame($expected, $treatment->webPresence());
        $this->assertSame($expected, $doctor->webPresence());
        $this->assertSame([$public, $bookable], [$expected->isPublic(), $expected->isBookable()]);
    }

    public function test_a_treatment_only_for_the_clinic_stays_off_the_web_and_gets_its_address_from_the_name(): void
    {
        Doctor::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->mountAction('create')
            // Uno nuevo se propone con su página en la web, sin reserva en línea.
            ->assertActionDataSet(['presence' => 'web'])
            ->assertMountedActionModalSee('Dirección en la web')
            ->assertMountedActionModalDontSee('Crear y crear otro')
            ->setActionData(['name' => 'Retenedor', 'duration_minutes' => 30, 'presence' => 'clinic'])
            // Lo que solo sirve a la página no se pide a lo que no va a salir en ella.
            ->assertMountedActionModalDontSee(['Dirección en la web', 'Mostrar el precio en la web', 'Resumen'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Tratamiento guardado');

        $treatment = Treatment::query()->where('name', 'Retenedor')->sole();
        $this->assertSame(['retenedor', false, false], [$treatment->slug, $treatment->is_public, $treatment->online_bookable]);

        // Otro con el mismo nombre no choca con la dirección del primero, aunque el primero se haya borrado.
        $treatment->delete();
        Livewire::test(ManageTreatments::class)
            ->callAction('create', data: ['name' => 'Retenedor', 'duration_minutes' => 30, 'presence' => 'clinic'])
            ->assertHasNoActionErrors();

        $this->assertSame('retenedor-2', Treatment::query()->where('name', 'Retenedor')->sole()->slug);
    }

    public function test_changing_where_a_treatment_appears_changes_its_page_and_its_online_booking(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $treatment->doctors()->attach(Doctor::factory()->create());
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->assertTableColumnStateSet('presence', 'Web y reserva', $treatment)
            ->mountAction(TestAction::make('edit')->table($treatment))
            ->assertActionDataSet(['presence' => 'booking'])
            ->setActionData(['presence' => 'web'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertTableColumnStateSet('presence', 'Página web', $treatment);

        $treatment->refresh();
        $this->assertSame([true, false], [$treatment->is_public, $treatment->online_bookable]);

        Livewire::test(ManageTreatments::class)
            ->callAction(TestAction::make('edit')->table($treatment), data: ['presence' => 'clinic'])
            ->assertHasNoActionErrors()
            ->assertTableColumnStateSet('presence', 'Solo consultorio', $treatment);

        $treatment->refresh();
        $this->assertSame([false, false], [$treatment->is_public, $treatment->online_bookable]);

        // De vuelta a la web, conserva la dirección que tenía.
        Livewire::test(ManageTreatments::class)
            ->callAction(TestAction::make('edit')->table($treatment), data: ['presence' => 'booking'])
            ->assertHasNoActionErrors();

        $this->assertSame([$treatment->slug, true, true], [$treatment->refresh()->slug, $treatment->is_public, $treatment->online_bookable]);
    }

    public function test_a_treatment_that_is_off_shows_no_level_in_the_list_and_says_so_in_its_form(): void
    {
        $treatment = Treatment::factory()->create(['is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->assertTableColumnStateSet('presence', null, $treatment)
            ->mountAction(TestAction::make('edit')->table($treatment))
            ->assertMountedActionModalSee('Está inactivo: no aparece en ningún lado. Al activarlo, vuelve a aparecer así.')
            ->setActionData(['is_active' => true])
            ->assertMountedActionModalDontSee('Está inactivo');
    }

    public function test_booking_online_without_a_page_is_kept_for_what_already_had_it_and_offered_to_nothing_else(): void
    {
        $legacy = Treatment::factory()->create(['name' => 'Urgencia', 'is_public' => false, 'online_bookable' => true]);
        $regular = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->assertTableColumnStateSet('presence', 'Solo reserva', $legacy)
            ->mountAction(TestAction::make('edit')->table($legacy))
            ->assertActionDataSet(['presence' => 'booking_only'])
            ->assertMountedActionModalSee('Solo reserva en línea, sin salir en la página')
            ->setActionData(['price_from' => 90])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $legacy->refresh();
        $this->assertSame([false, true, '90.00'], [$legacy->is_public, $legacy->online_bookable, $legacy->price_from]);

        // A lo que no estaba así no se le ofrece, ni se le acepta.
        Livewire::test(ManageTreatments::class)
            ->mountAction(TestAction::make('edit')->table($regular))
            ->assertMountedActionModalDontSee('Solo reserva en línea, sin salir en la página')
            ->setActionData(['presence' => 'booking_only'])
            ->callMountedAction()
            ->assertHasActionErrors(['presence']);

        $this->assertSame([true, true], [$regular->refresh()->is_public, $regular->online_bookable]);
    }

    public function test_the_list_of_treatments_says_where_the_time_comes_from(): void
    {
        $own = Treatment::factory()->create(['name' => 'Endodoncia', 'duration_minutes' => 60, 'buffer_minutes' => 20]);
        $general = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30, 'buffer_minutes' => null]);
        $slow = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $slow->treatments()->attach($own, ['duration_minutes' => 90]);
        $slow->treatments()->attach($general);
        // Un doctor que ya no atiende no cuenta, aunque tenga su duración.
        Doctor::factory()->create(['name' => 'Dr. Luis Ramos', 'is_active' => false])->treatments()->attach($own, ['duration_minutes' => 45]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->assertSeeInOrder(['Duración de la cita', 'Precio normal', 'Dónde aparece'])
            ->assertTableColumnHasDescription('duration_minutes', '+20 de limpieza (propia) · Dra. Ana Torres: 90 min', $own)
            ->assertTableColumnHasDescription('duration_minutes', '+10 de limpieza (la general)', $general)
            // En su ficha, la misma cuenta con palabras.
            ->mountAction(TestAction::make('edit')->table($own))
            ->assertMountedActionModalSee('A Dra. Ana Torres se le reservan 90 min (puesta en su ficha). Con los demás doctores se reserva esta duración.')
            ->assertMountedActionModalDontSee('Dr. Luis Ramos')
            ->unmountAction()
            ->mountAction(TestAction::make('edit')->table($general))
            ->assertMountedActionModalSee('Es lo que se reserva con cualquier doctor. Cada doctor puede tener su propia duración, en su ficha.');
    }

    public function test_a_doctor_is_moved_to_the_web_and_to_the_online_booking_with_one_choice(): void
    {
        $doctor = Doctor::factory()->create(['name' => 'Dra. Ana Torres', 'slug' => 'dra-ana-torres', 'is_public' => false, 'accepts_online_booking' => false]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListDoctors::class)->assertTableColumnStateSet('presence', 'Solo consultorio', $doctor);

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertSchemaStateSet(['presence' => 'clinic'])
            ->assertFormFieldHidden('slug')
            ->fillForm(['presence' => 'booking'])
            // Al pasar a la web aparece su dirección, con la que ya tenía.
            ->assertFormFieldVisible('slug')
            ->assertSchemaStateSet(['slug' => 'dra-ana-torres'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Doctor guardado');

        $doctor->refresh();
        $this->assertSame([true, true], [$doctor->is_public, $doctor->accepts_online_booking]);

        Livewire::test(ListDoctors::class)->assertTableColumnStateSet('presence', 'Web y reserva', $doctor);

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertSchemaStateSet(['presence' => 'booking'])
            ->fillForm(['presence' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $doctor->refresh();
        $this->assertSame([true, false], [$doctor->is_public, $doctor->accepts_online_booking]);
    }

    public function test_a_new_doctor_who_goes_straight_to_the_web_can_choose_their_address(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateDoctor::class)
            ->fillForm(['name' => 'Dr. Luis Ramos', 'access' => 'none', 'presence' => 'web', 'slug' => 'luis-ramos'])
            ->call('create')
            ->assertHasNoFormErrors();

        $doctor = Doctor::query()->where('name', 'Dr. Luis Ramos')->sole();
        $this->assertSame(['luis-ramos', true, false], [$doctor->slug, $doctor->is_public, $doctor->accepts_online_booking]);

        // La dirección de otro doctor no se repite.
        Livewire::test(CreateDoctor::class)
            ->fillForm(['name' => 'Dr. Luis Ramos', 'access' => 'none', 'presence' => 'web', 'slug' => 'luis-ramos'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }
}
