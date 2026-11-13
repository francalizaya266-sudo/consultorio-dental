<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ConsentTemplates\Pages\ManageConsentTemplates;
use App\Models\ConsentTemplate;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ConsentService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class ConsentTemplatesPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_with_two_texts_in_use_for_the_same_thing_the_newest_is_proposed_and_the_list_says_so(): void
    {
        $this->freezeMonday();
        $extraction = Treatment::factory()->create(['name' => 'Extracción simple']);
        // El de siempre lleva varias correcciones; el nuevo recién se escribe.
        $old = ConsentTemplate::create(['name' => 'General antiguo', 'body' => 'Texto antiguo.', 'version' => 7]);
        $own = ConsentTemplate::create(['name' => 'De extracción', 'treatment_id' => $extraction->id, 'body' => 'Extracción.']);
        $this->travel(1)->days();
        $new = ConsentTemplate::create(['name' => 'General nuevo', 'body' => 'Texto nuevo.']);
        $consents = app(ConsentService::class);

        // Sin tratamiento propio se propone el general más reciente, no el de más versiones.
        $this->assertTrue($new->is($consents->templateFor(null)));
        $this->assertTrue($new->is($consents->templateFor(Treatment::factory()->create())));
        $this->assertTrue($own->is($consents->templateFor($extraction)));
        $this->assertSame([false, true, null], [$consents->isProposedFirst($old->fresh()), $consents->isProposedFirst($new->fresh()), $consents->isProposedFirst($own->fresh())]);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageConsentTemplates::class)
            ->assertSee([
                'Hay otro más reciente en uso para lo mismo: este solo sale si se elige al firmar.',
                'Hay otro en uso para lo mismo: al firmar se propone este, el más reciente.',
            ])
            ->call('updateTableColumnState', 'is_active', (string) $old->id, false)
            ->assertNotified('"General antiguo" ya no se usa')
            // Queda uno solo en uso: ya no hay nada que aclarar.
            ->assertDontSee('en uso para lo mismo');

        $this->assertNull($consents->isProposedFirst($new->fresh()));
    }

    public function test_a_doctor_reads_the_texts_they_sign_with_patients_but_does_not_write_them(): void
    {
        $template = ConsentTemplate::create(['name' => 'Consentimiento general', 'body' => 'Yo, {firmante}, autorizo el tratamiento.']);

        $this->actingAs(Doctor::factory()->create()->user);
        Livewire::test(ManageConsentTemplates::class)
            ->assertActionHidden(TestAction::make('edit')->table($template))
            ->mountAction(TestAction::make('view')->table($template))
            ->assertMountedActionModalSee('Consentimiento general')
            ->assertSchemaStateSet(['body' => 'Yo, {firmante}, autorizo el tratamiento.']);

        // Quien los escribe no necesita "Ver": tiene "Editar".
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ManageConsentTemplates::class)
            ->assertActionHidden(TestAction::make('view')->table($template))
            ->assertActionVisible(TestAction::make('edit')->table($template));
    }
}
