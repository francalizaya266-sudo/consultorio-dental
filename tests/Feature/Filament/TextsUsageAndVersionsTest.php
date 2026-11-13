<?php

namespace Tests\Feature\Filament;

use App\Enums\SnippetField;
use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Resources\ConsentTemplates\Pages\ManageConsentTemplates;
use App\Filament\Resources\TextSnippets\Pages\ManageTextSnippets;
use App\Models\ConsentTemplate;
use App\Models\Doctor;
use App\Models\Setting;
use App\Models\TextSnippet;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Los textos: cuánto se usa cada opción rápida, cómo queda un consentimiento antes de guardarlo y qué decía cada versión. */
class TextsUsageAndVersionsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_the_list_of_quick_options_says_how_many_times_each_one_was_used(): void
    {
        $this->freezeMonday();
        $ana = Doctor::factory()->create()->user;
        $luis = Doctor::factory()->create()->user;
        $often = TextSnippet::create(['field' => SnippetField::Procedures, 'label' => 'Profilaxis completa', 'body' => '...']);
        $once = TextSnippet::create(['field' => SnippetField::Procedures, 'label' => 'Resina simple', 'body' => '...']);
        $never = TextSnippet::create(['field' => SnippetField::Procedures, 'label' => 'De prueba', 'body' => '...']);
        // Entre todos: dos veces Ana y una Luis.
        $often->recordUse($ana);
        $often->recordUse($ana);
        $this->travelTo('2026-11-05 10:00');
        $often->recordUse($luis);
        $once->recordUse($luis);
        $this->travelTo('2026-11-12 10:00');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTextSnippets::class)
            ->assertTableColumnFormattedStateSet('times_used', '3 veces', $often)
            ->assertTableColumnHasDescription('times_used', 'La última, hace 1 semana', $often)
            ->assertTableColumnFormattedStateSet('times_used', '1 vez', $once)
            ->assertTableColumnFormattedStateSet('times_used', 'Nunca', $never)
            ->assertTableColumnDoesNotHaveDescription('times_used', 'La última, hace 1 semana', $never)
            // Ordenada por uso, quedan primero las que nadie usa: las que se pueden archivar.
            ->sortTable('times_used')
            ->assertCanSeeTableRecords([$never, $once, $often], inOrder: true)
            ->sortTable('times_used', 'desc')
            ->assertCanSeeTableRecords([$often, $once, $never], inOrder: true);
    }

    public function test_a_consent_text_is_seen_with_sample_data_before_saving_it(): void
    {
        $this->freezeMonday();
        Setting::set('clinic.district', 'Juliaca');
        Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $treatment = Treatment::factory()->create(['name' => 'Extracción simple']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageConsentTemplates::class)
            ->mountAction('create')
            // Sin texto todavía no hay nada que mostrar.
            ->assertMountedActionModalDontSee('Así queda al firmar')
            ->setActionData(['name' => 'Cirugía', 'body' => "Yo, {firmante}, con {documento}, {calidad}, autorizo a {doctor} a realizar {tratamiento} a {paciente}.\n\n{ciudad}, {fecha}."])
            ->assertMountedActionModalSee([
                'Así queda al firmar',
                'Yo, María Quispe Mamani, con documento N.º 45678912, en mi calidad de paciente, autorizo a Dra. Ana Torres a realizar el tratamiento indicado a María Quispe Mamani.',
                // Los riesgos del paciente van solos antes de la fecha, aunque el texto no los nombre.
                'Por los antecedentes del paciente, se me explicó además que:',
                'Juliaca, 2 de noviembre de 2026.',
            ])
            // Con el tratamiento elegido, la vista previa lo nombra.
            ->setActionData(['treatment_id' => $treatment->id])
            ->assertMountedActionModalSee('a realizar extracción simple a María Quispe Mamani.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // La vista previa no se guarda con el texto: quedan las llaves, para llenarlas con cada paciente.
        $this->assertStringContainsString('{firmante}', ConsentTemplate::query()->where('name', 'Cirugía')->sole()->body);
    }

    public function test_what_each_previous_version_of_a_consent_text_said_can_be_read(): void
    {
        $this->travelTo('2026-10-01 10:00');
        $template = ConsentTemplate::create(['name' => 'General', 'body' => 'Primera redacción.']);
        $untouched = ConsentTemplate::create(['name' => 'Ortodoncia', 'body' => 'Sin cambios.']);
        $this->actingAs(User::factory()->admin()->create(['name' => 'Carla Vega']));

        $this->travelTo('2026-10-20 10:00');
        Livewire::test(ManageConsentTemplates::class)
            // Con una sola versión no hay nada anterior que ver.
            ->assertActionHidden(TestAction::make('versions')->table($template))
            ->callAction(TestAction::make('edit')->table($template), data: ['body' => 'Segunda redacción.'])
            ->assertHasNoActionErrors();

        $this->travelTo('2026-11-05 10:00');
        Livewire::test(ManageConsentTemplates::class)
            // Cambiar solo el nombre no crea una versión.
            ->callAction(TestAction::make('edit')->table($template), data: ['name' => 'Consentimiento general'])
            ->callAction(TestAction::make('edit')->table($template), data: ['body' => 'Tercera redacción.'])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('versions')->table($untouched))
            ->mountAction(TestAction::make('versions')->table($template))
            ->assertMountedActionModalSee('Versiones anteriores de "Consentimiento general"');

        $this->assertSame(
            [
                ['number' => 2, 'until' => '05/11/2026', 'by' => 'Carla Vega', 'body' => 'Segunda redacción.'],
                ['number' => 1, 'until' => '20/10/2026', 'by' => 'Carla Vega', 'body' => 'Primera redacción.'],
            ],
            ConsentTemplateResource::previousVersions($template->refresh()),
        );
        $this->assertSame([3, 'Tercera redacción.'], [$template->version, $template->body]);
    }

    public function test_a_doctor_reads_the_previous_versions_too(): void
    {
        $template = ConsentTemplate::create(['name' => 'General', 'body' => 'Primera redacción.']);
        $template->update(['body' => 'Segunda redacción.']);
        $this->actingAs(Doctor::factory()->create()->user);

        Livewire::test(ManageConsentTemplates::class)
            ->assertActionVisible(TestAction::make('versions')->table($template))
            ->mountAction(TestAction::make('versions')->table($template))
            ->assertMountedActionModalSee(['Versión 1', 'Primera redacción.']);
    }
}
