<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Settings;
use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Resources\ConsentTemplates\Pages\ManageConsentTemplates;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\TextSnippets\TextSnippetResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\ConsentTemplate;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/** "Configuración" va en cuatro grupos con nombre, y los textos repartidos en varias pantallas se enlazan entre sí. */
class SettingsMenuGroupsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_configuracion_goes_in_four_named_groups(): void
    {
        // Las copias de seguridad llevan la historia clínica: las ve la administradora que también atiende.
        $this->actingAs(User::factory()->admin(isDentist: true)->create());

        $this->assertSame([
            'Consultorio: Ajustes',
            'Consultorio: Copias de seguridad',
            'Consultorio: Historial de cambios',
            'Catálogo: Tratamientos',
            'Catálogo: Paquetes',
            'Equipo: Doctores',
            'Equipo: Usuarios',
            'Textos: Opciones rápidas',
            'Textos: Textos de consentimiento',
        ], $this->settingsMenu($this->get(Settings::getUrl())->assertOk()));
    }

    public function test_a_doctor_sees_their_part_of_configuracion_under_the_same_groups(): void
    {
        $this->actingAs(Doctor::factory()->create()->user);

        $this->assertSame([
            'Catálogo: Tratamientos',
            'Catálogo: Paquetes',
            'Textos: Opciones rápidas',
            'Textos: Textos de consentimiento',
        ], $this->settingsMenu($this->get(TreatmentResource::getUrl('index'))->assertOk()));
    }

    public function test_each_page_of_texts_says_where_the_other_texts_are(): void
    {
        $messages = MessageTemplateResource::getUrl('index');
        $snippets = TextSnippetResource::getUrl('index');
        $consents = ConsentTemplateResource::getUrl('index');
        $treatments = TreatmentResource::getUrl('index');
        $link = fn (string $url, string $text): string => '<a href="'.e($url).'">'.e($text).'</a>';
        $this->actingAs(User::factory()->admin()->create());

        // Cada pantalla enlaza a las otras tres, no a sí misma.
        $this->get($messages)->assertOk()
            ->assertSee('Otros textos: '.$link($snippets, 'Opciones rápidas de la historia').' · '.$link($consents, 'Textos de consentimiento').' · '.$link($treatments, 'Indicaciones antes y después de cada tratamiento').'.', escape: false);
        $this->get($snippets)->assertOk()
            ->assertSee('Otros textos: '.$link($messages, 'Mensajes de WhatsApp').' · '.$link($consents, 'Textos de consentimiento').' · '.$link($treatments, 'Indicaciones antes y después de cada tratamiento').'.', escape: false);
        $this->get($consents)->assertOk()
            ->assertSee('Otros textos: '.$link($messages, 'Mensajes de WhatsApp').' · '.$link($snippets, 'Opciones rápidas de la historia').' · '.$link($treatments, 'Indicaciones antes y después de cada tratamiento').'.', escape: false);

        // A quien no puede abrir una de esas pantallas no se le ofrece el enlace.
        $this->actingAs(Doctor::factory()->create()->user);

        $this->get($snippets)->assertOk()
            ->assertSee('Otros textos: '.$link($consents, 'Textos de consentimiento').' · '.$link($treatments, 'Indicaciones antes y después de cada tratamiento').'.', escape: false)
            ->assertDontSee('Mensajes de WhatsApp');
    }

    public function test_the_lists_of_configuracion_show_twenty_five_rows_before_turning_the_page(): void
    {
        $people = User::factory()->count(12)->create();
        $texts = collect(range(1, 26))->map(fn (int $number) => ConsentTemplate::create(['name' => sprintf('Texto %02d', $number), 'body' => '...']));
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageUsers::class)->assertCanSeeTableRecords($people);

        // Los textos de consentimiento no tenían páginas: ahora, como las demás listas, 25 por página.
        Livewire::test(ManageConsentTemplates::class)
            ->assertCanSeeTableRecords($texts->take(25))
            ->assertCanNotSeeTableRecords($texts->skip(25));
    }

    /**
     * Las entradas de Configuración tal como salen en el menú, con su grupo: "Catálogo: Tratamientos".
     *
     * @return list<string>
     */
    private function settingsMenu(TestResponse $page): array
    {
        preg_match_all('/<li[^>]*data-menu-group="([^"]+)"[^>]*>.*?fi-sidebar-item-label[^>]*>\s*(.*?)\s*<\/span>/s', $page->getContent(), $entries, PREG_SET_ORDER);

        return array_map(fn (array $entry): string => html_entity_decode($entry[1]).': '.trim(strip_tags($entry[2])), $entries);
    }
}
