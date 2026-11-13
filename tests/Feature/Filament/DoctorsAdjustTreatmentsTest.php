<?php

namespace Tests\Feature\Filament;

use App\Enums\TreatmentKind;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Qué se ofrece, a qué precio, con quién y dónde aparece lo decide el administrador. Los doctores ajustan lo clínico
 * de cada tratamiento: la duración, el seguimiento y las indicaciones.
 */
class DoctorsAdjustTreatmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Treatment $cleaning;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleaning = Treatment::factory()->create([
            'name' => 'Limpieza dental', 'slug' => 'limpieza-dental', 'price_from' => 120, 'duration_minutes' => 30,
            'is_active' => true, 'is_public' => true, 'online_bookable' => false, 'is_evaluation' => false, 'show_price' => true,
            'summary' => 'Quita el sarro y las manchas.',
        ]);
        $this->doctor = Doctor::factory()->create();
        $this->cleaning->doctors()->attach($this->doctor);
    }

    public function test_a_doctor_adjusts_the_duration_the_follow_up_and_the_instructions(): void
    {
        $this->actingAs($this->doctor->user);

        Livewire::test(ManageTreatments::class)
            ->mountAction(TestAction::make('edit')->table($this->cleaning))
            ->assertMountedActionModalSee('El nombre, el precio, quién lo atiende y dónde aparece los cambia el administrador.')
            ->fillForm([
                'duration_minutes' => 45,
                'buffer_minutes' => 15,
                'sessions_label' => '1 sesión',
                'kind' => TreatmentKind::Cleaning->value,
                'checkup_months' => 4,
                'next_session_days' => 120,
                'before_instructions' => 'Ven habiendo comido.',
                'aftercare' => 'No comas por dos horas.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Tratamiento guardado');

        $this->assertSame(
            [45, 15, '1 sesión', TreatmentKind::Cleaning, 4, 120, 'Ven habiendo comido.', 'No comas por dos horas.'],
            array_values($this->cleaning->fresh()->only(['duration_minutes', 'buffer_minutes', 'sessions_label', 'kind', 'checkup_months', 'next_session_days', 'before_instructions', 'aftercare'])),
        );
    }

    public function test_a_doctor_does_not_change_the_price_who_attends_or_where_it_appears(): void
    {
        $other = Doctor::factory()->create();
        $this->actingAs($this->doctor->user);

        $page = Livewire::test(ManageTreatments::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('duplicate')->table($this->cleaning))
            ->mountAction(TestAction::make('edit')->table($this->cleaning));

        foreach (['name', 'is_active', 'doctors', 'is_evaluation', 'price_from', 'presence', 'slug', 'show_price', 'summary'] as $field) {
            $page->assertFormFieldDisabled($field);
        }

        foreach (['duration_minutes', 'buffer_minutes', 'sessions_label', 'kind', 'checkup_months', 'next_session_days', 'before_instructions', 'aftercare'] as $field) {
            $page->assertFormFieldEnabled($field);
        }

        // Aunque alguien arme el pedido a mano, lo que no le toca no se guarda.
        $page
            ->fillForm([
                'name' => 'Limpieza gratis',
                'is_active' => false,
                'doctors' => [$other->id],
                'is_evaluation' => true,
                'price_from' => 1,
                'presence' => 'booking',
                'slug' => 'otra',
                'show_price' => false,
                'summary' => 'Gratis hoy.',
                'duration_minutes' => 40,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $saved = $this->cleaning->fresh();
        $this->assertSame(
            ['Limpieza dental', true, false, '120.00', true, false, 'limpieza-dental', true, 'Quita el sarro y las manchas.', 40],
            [$saved->name, $saved->is_active, $saved->is_evaluation, $saved->price_from, $saved->is_public, $saved->online_bookable, $saved->slug, $saved->show_price, $saved->summary, $saved->duration_minutes],
        );
        $this->assertSame([$this->doctor->id], $saved->doctors()->pluck('doctors.id')->all());

        // Ni con el interruptor "Activo" de la lista.
        Livewire::test(ManageTreatments::class)
            ->call('updateTableColumnState', 'is_active', (string) $this->cleaning->id, false)
            ->assertNotNotified('"Limpieza dental" ya no se ofrece');

        $this->assertTrue($this->cleaning->fresh()->is_active);
    }

    public function test_the_administrator_still_changes_all_of_it(): void
    {
        $other = Doctor::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatments::class)
            ->assertActionVisible('create')
            ->assertActionVisible(TestAction::make('duplicate')->table($this->cleaning))
            ->mountAction(TestAction::make('edit')->table($this->cleaning))
            ->assertMountedActionModalDontSee('los cambia el administrador')
            ->assertFormFieldEnabled('price_from')
            ->assertFormFieldEnabled('presence')
            ->fillForm(['name' => 'Limpieza y profilaxis', 'price_from' => 150, 'doctors' => [$this->doctor->id, $other->id], 'presence' => 'clinic'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $saved = $this->cleaning->fresh();
        $this->assertSame(['Limpieza y profilaxis', '150.00', false], [$saved->name, $saved->price_from, $saved->is_public]);
        $this->assertEqualsCanonicalizing([$this->doctor->id, $other->id], $saved->doctors()->pluck('doctors.id')->all());

        Livewire::test(ManageTreatments::class)
            ->call('updateTableColumnState', 'is_active', (string) $this->cleaning->id, false)
            ->assertNotified('"Limpieza y profilaxis" ya no se ofrece');

        $this->assertFalse($this->cleaning->fresh()->is_active);
    }
}
