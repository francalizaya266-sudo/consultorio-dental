<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WebPages;
use App\Models\Setting;
use App\Models\User;
use App\Services\Web\PublicImages;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Página web › Textos de las páginas: el encabezado y la franja final de cada página, editados desde el panel. */
class WebPagesPanelTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_sheet_opens_with_the_texts_of_the_system_and_the_web_shows_what_is_saved(): void
    {
        Livewire::test(WebPages::class)
            ->assertSchemaStateSet([
                'web.pages.servicios.kicker' => 'Tu sonrisa, nuestra prioridad',
                'web.pages.servicios.title' => 'Nuestros servicios',
                'web.pages.contacto.title' => 'Contácta*nos*',
                'web.pages.servicios.closing_title' => '¿Listo para cuidar tu sonrisa?',
                'web.pages.inicio.closing_title' => 'Tu salud bucal, en buenas manos',
            ])
            ->fillForm(['web' => ['pages' => [
                'servicios' => ['kicker' => '', 'title' => 'Tratamientos *para* tu sonrisa', 'lead' => 'Elige el tuyo y reserva.', 'closing_title' => '¿Empezamos?', 'closing_text' => ''],
                'inicio' => ['closing_title' => 'Te esperamos en Juliaca'],
            ]]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Textos de las páginas guardados');

        $this->assertSame(
            [null, 'Tratamientos *para* tu sonrisa', 'Elige el tuyo y reserva.', '¿Empezamos?', null],
            array_map(fn (string $field) => Setting::get('web.pages.servicios.'.$field), ['kicker', 'title', 'lead', 'closing_title', 'closing_text']),
        );

        $this->get(route('site.treatments'))
            ->assertOk()
            ->assertSeeInOrder(['<h1>Tratamientos <em>para</em> tu sonrisa</h1>', '<p class="lead">Elige el tuyo y reserva.</p>', '<strong>¿Empezamos?</strong>'], false)
            ->assertDontSee(['class="kicker"', 'Reserva tu cita en línea y te confirmamos por WhatsApp.'], false);

        // Lo que no se tocó queda como venía, y el cierre general llega al inicio.
        $this->get(route('site.team'))->assertOk()->assertSee('<h1>Nuestro <em>equipo</em></h1>', false);
        $this->get(route('site.home'))->assertOk()->assertSee('<strong>Te esperamos en Juliaca</strong>', false);
    }

    public function test_a_title_is_required_and_the_texts_have_a_limit(): void
    {
        Livewire::test(WebPages::class)
            ->fillForm(['web' => ['pages' => [
                'equipo' => ['title' => '', 'kicker' => str_repeat('a', 51), 'lead' => str_repeat('a', 221), 'closing_title' => '', 'closing_text' => str_repeat('a', 121)],
                'inicio' => ['closing_title' => ''],
            ]]])
            ->call('save')
            ->assertHasFormErrors([
                'web.pages.equipo.title' => 'required',
                'web.pages.equipo.kicker',
                'web.pages.equipo.lead',
                'web.pages.equipo.closing_title' => 'required',
                'web.pages.equipo.closing_text',
                'web.pages.inicio.closing_title' => 'required',
            ]);

        $this->assertNull(Setting::query()->where('key', 'web.pages.equipo.title')->first());
    }

    public function test_the_points_that_distinguish_the_team_are_written_in_the_team_tab(): void
    {
        Livewire::test(WebPages::class)
            ->assertSee(['Lo que distingue al equipo', 'Texto del punto 4'])
            ->assertSchemaStateSet([
                'web.pages.equipo.value1_title' => 'Colegiados',
                'web.pages.equipo.value1_text' => 'Cada doctor con su número de colegiatura',
                'web.pages.equipo.value4_title' => 'Trato humano',
            ])
            ->fillForm(['web' => ['pages' => ['equipo' => ['value1_title' => 'Atención en quechua', 'value1_text' => '', 'value2_title' => '']]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Atención en quechua', null, null], array_map(fn (string $field) => Setting::get('web.pages.equipo.'.$field), ['value1_title', 'value1_text', 'value2_title']));

        // Los puntos van en una franja angosta: cada uno tiene su límite.
        Livewire::test(WebPages::class)
            ->fillForm(['web' => ['pages' => ['equipo' => ['value3_title' => str_repeat('a', 31), 'value3_text' => str_repeat('a', 61)]]]])
            ->call('save')
            ->assertHasFormErrors(['web.pages.equipo.value3_title', 'web.pages.equipo.value3_text']);

        $this->assertSame('Compromiso', Setting::get('web.pages.equipo.value3_title'));
    }

    public function test_the_admin_uploads_and_removes_the_photo_of_a_page(): void
    {
        Storage::fake(PublicImages::DISK);

        Livewire::test(WebPages::class)
            ->fillForm(['web' => ['pages' => ['contacto' => ['photo' => UploadedFile::fake()->image('fachada.jpg', 2400, 1200)]]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $photo = Setting::get('web.pages.contacto.photo');
        $this->assertStringStartsWith('paginas/', $photo);
        Storage::disk(PublicImages::DISK)->assertExists($photo);

        $this->get(route('site.contact'))->assertOk()->assertSee('<figure class="band-photo"><img src="'.PublicImages::url($photo).'"', false);
        $this->get(route('site.team'))->assertOk()->assertDontSee('band-photo');

        Livewire::test(WebPages::class)
            ->fillForm(['web' => ['pages' => ['contacto' => ['photo' => null]]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(Setting::get('web.pages.contacto.photo'));
        $this->get(route('site.contact'))->assertOk()->assertDontSee('band-photo');
    }

    public function test_each_page_has_its_tab_with_a_link_to_see_it(): void
    {
        Livewire::test(WebPages::class)
            ->assertSeeInOrder(['Servicios', 'Equipo', 'Contacto', 'Preguntas frecuentes', 'Promociones', 'Antes y después', 'Opiniones', 'Mi cuenta', 'Cierre general'])
            ->assertSee([route('site.treatments'), route('site.faq'), route('site.reviews'), route('site.account')])
            // Las páginas sin cierre propio dicen con qué cierran.
            ->assertSee('Esta página cierra con la frase de la pestaña Cierre general.')
            ->assertSee('con el botón para escribir por WhatsApp');
    }
}
