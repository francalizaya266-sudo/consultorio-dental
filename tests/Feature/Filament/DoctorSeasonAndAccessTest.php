<?php

namespace Tests\Feature\Filament;

use App\Enums\PlanPaymentMode;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\TreatmentPackages\Pages\ManageTreatmentPackages;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** La ficha del doctor: tramos del horario que valen por una temporada y quitarle el acceso al panel. Y el paquete que se paga por sesión. */
class DoctorSeasonAndAccessTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
    }

    public function test_a_range_of_the_schedule_can_be_valid_only_for_a_season(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00']], [1])->create();
        $doctor->treatments()->attach($treatment);
        $this->actingAs(User::factory()->admin()->create());
        $season = fn (string $item): TestAction => TestAction::make('season')->schemaComponent('schedules')->arguments(['item' => $item]);

        $page = Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()]);
        $range = array_key_first($page->get('data.schedules'));

        $page->assertDontSee('Solo del')
            // "Vale hasta" no puede quedar antes de "Vale desde".
            ->callAction($season($range), data: ['valid_from' => '2027-03-15', 'valid_until' => '2026-12-15'])
            ->assertHasActionErrors(['valid_until'])
            ->unmountAction()
            ->callAction($season($range), data: ['valid_from' => '2026-12-15', 'valid_until' => '2027-03-15'])
            ->assertHasNoActionErrors()
            ->assertSee('Solo del 15/12/2026 al 15/03/2027')
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $doctor->schedules()->sole();
        $this->assertSame(['2026-12-15', '2027-03-15'], [$saved->valid_from->toDateString(), $saved->valid_until->toDateString()]);

        // La agenda ofrece ese tramo solo dentro de la temporada.
        $slots = fn (string $monday): int => app(AvailabilityService::class)->slotsFor($doctor, $treatment, CarbonImmutable::parse($monday))->count();
        $this->assertSame(0, $slots('2026-11-09'));
        $this->assertGreaterThan(0, $slots('2026-12-21'));
        $this->assertSame(0, $slots('2027-03-22'));

        // Al volver a abrir la ficha se ve la temporada, y se puede quitar: vuelve a valer todo el año.
        $page = Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])->assertSee('Solo del 15/12/2026 al 15/03/2027');
        $page->callAction($season(array_key_first($page->get('data.schedules'))), data: ['valid_from' => null, 'valid_until' => null])
            ->assertDontSee('Solo del')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([null, null], [$doctor->schedules()->sole()->valid_from, $doctor->schedules()->sole()->valid_until]);
        $this->assertGreaterThan(0, $slots('2026-11-09'));
    }

    public function test_two_ranges_of_the_same_hours_do_not_cross_if_their_seasons_do_not_meet(): void
    {
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00']], [1])->create();
        $this->actingAs(User::factory()->admin()->create());
        $season = fn (string $item): TestAction => TestAction::make('season')->schemaComponent('schedules')->arguments(['item' => $item]);
        $addMonday = TestAction::make('addRange')->schemaComponent('schedules')->arguments(['weekday' => 1]);

        $page = Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])->callAction($addMonday);
        [$usual, $summer] = array_keys($page->get('data.schedules'));

        // El de verano empieza antes y se cruza con el de siempre: mientras valgan a la vez, no se guarda.
        $page->set("data.schedules.{$summer}.starts_at", '2026-11-02 08:00:00')
            ->set("data.schedules.{$summer}.ends_at", '2026-11-02 12:00:00')
            ->callAction($season($summer), data: ['valid_from' => '2026-12-15', 'valid_until' => '2027-03-15'])
            ->call('save')
            ->assertHasFormErrors(['schedules'])
            ->assertSee('El lunes tiene dos tramos que se cruzan: 08:00–12:00 y 09:00–13:00. Deja uno solo, cámbiales la hora o ponles temporadas que no coincidan.');

        // Con el de siempre hasta el día anterior al verano, conviven.
        $page->callAction($season($usual), data: ['valid_from' => null, 'valid_until' => '2026-12-14'])
            ->assertSee(['Solo hasta el 14/12/2026', 'Solo del 15/12/2026 al 15/03/2027'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [['09:00', null, '2026-12-14'], ['08:00', '2026-12-15', '2027-03-15']],
            $doctor->schedules()->orderBy('id')->get()->map(fn ($range) => [substr((string) $range->starts_at, 0, 5), $range->valid_from?->toDateString(), $range->valid_until?->toDateString()])->all(),
        );
    }

    public function test_copying_a_day_takes_its_season_along(): void
    {
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00']], [1])->create();
        $doctor->schedules()->update(['valid_from' => '2026-12-15', 'valid_until' => '2027-03-15']);
        $this->actingAs(User::factory()->admin()->create());

        $page = Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()]);
        $page->callAction(TestAction::make('copyDay')->schemaComponent('schedules')->arguments(['item' => array_key_first($page->get('data.schedules'))]), data: ['days' => [3]])
            ->call('save')
            ->assertHasNoFormErrors();

        $wednesday = $doctor->schedules()->where('weekday', 3)->sole();
        $this->assertSame(['2026-12-15', '2027-03-15'], [$wednesday->valid_from->toDateString(), $wednesday->valid_until->toDateString()]);
    }

    public function test_the_access_to_the_panel_is_taken_away_from_the_page_of_the_doctor(): void
    {
        $doctor = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $this->actingAs($admin = User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertFormFieldVisible('can_enter')
            ->assertSchemaStateSet(['can_enter' => true])
            ->fillForm(['can_enter' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        // Sigue siendo su usuario y sigue de doctor en la agenda: solo ya no entra.
        $this->assertSame([false, $doctor->user_id, true], [$doctor->user->refresh()->is_active, $doctor->refresh()->user_id, $doctor->is_active]);
        $this->assertFalse($doctor->user->canAccessPanel(filament()->getPanel('admin')));

        // Queda en el historial con el nombre de quien lo hizo.
        $change = AuditLog::query()->where('auditable_type', User::class)->where('auditable_id', $doctor->user_id)->where('event', 'updated')->sole();
        $this->assertSame([$admin->id, ['is_active' => false]], [$change->user_id, $change->new_values]);

        // Y se le devuelve del mismo modo.
        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertSchemaStateSet(['can_enter' => false])
            ->fillForm(['can_enter' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($doctor->user->refresh()->is_active);
    }

    public function test_nobody_takes_their_own_access_away_and_a_doctor_without_user_has_nothing_to_take(): void
    {
        $owner = User::factory()->admin(isDentist: true)->create();
        $own = Doctor::factory()->create(['user_id' => $owner->id]);
        $withoutUser = Doctor::factory()->create(['user_id' => null]);
        $this->actingAs($owner);

        Livewire::test(EditDoctor::class, ['record' => $own->getKey()])
            ->assertFormFieldDisabled('can_enter')
            ->assertSee('Es tu propio usuario: no puedes quitarte el acceso.')
            ->set('data.can_enter', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($owner->refresh()->is_active);

        Livewire::test(EditDoctor::class, ['record' => $withoutUser->getKey()])->assertFormFieldHidden('can_enter');
    }

    public function test_a_package_paid_per_session_says_so_with_its_own_switch(): void
    {
        $treatment = Treatment::factory()->create(['price_from' => 200]);
        $this->actingAs(User::factory()->admin()->create());
        Repeater::fake();

        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction('create')
            // Uno nuevo viene con pago único.
            ->assertActionDataSet(['allows_upfront' => true, 'per_session' => false])
            ->setActionData(['name' => 'Tres limpiezas', 'price' => 500, 'items' => [['treatment_id' => $treatment->id, 'quantity' => 3, 'sessions_planned' => 3]]])
            // Al marcar "Por sesión" se apagan las otras dos formas.
            ->setActionData(['per_session' => true])
            ->assertActionDataSet(['allows_upfront' => false, 'allows_installments' => false])
            ->assertMountedActionModalSee('Por sesión: paga lo de cada sesión al terminarla.')
            // Y al encender otra, "Por sesión" se apaga sola.
            ->setActionData(['allows_upfront' => true])
            ->assertActionDataSet(['per_session' => false])
            ->setActionData(['per_session' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $package = TreatmentPackage::query()->where('name', 'Tres limpiezas')->sole();
        $this->assertSame([false, false, PlanPaymentMode::PerSession], [$package->allows_upfront, $package->allows_installments, $package->defaultPaymentMode()]);

        // Al abrirlo de nuevo, el interruptor dice cómo quedó; apagarlo lo devuelve al pago único.
        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction(TestAction::make('edit')->table($package))
            ->assertActionDataSet(['per_session' => true])
            ->setActionData(['per_session' => false])
            ->assertActionDataSet(['allows_upfront' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(PlanPaymentMode::Upfront, $package->refresh()->defaultPaymentMode());
    }
}
