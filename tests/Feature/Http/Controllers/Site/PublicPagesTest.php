<?php

namespace Tests\Feature\Http\Controllers\Site;

use App\Enums\DiscountType;
use App\Models\Campaign;
use App\Models\Faq;
use App\Models\Review;
use App\Models\Setting;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::query()->create($attributes + [
            'name' => 'Limpieza de verano',
            'slug' => 'limpieza-verano',
            'summary' => 'Limpieza con 20% de descuento.',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function test_the_home_shows_the_next_free_times_prices_team_and_how_to_get_there(): void
    {
        $this->freezeMonday();
        Setting::set('clinic.address', 'Av. Larco 123');
        Setting::set('clinic.district', 'Miraflores');
        $evaluation = Treatment::factory()->evaluation()->create(['price_from' => 50]);
        $hidden = Treatment::factory()->create(['name' => 'Tratamiento interno', 'is_public' => false]);
        $doctor = $this->doctorOffering($evaluation, $hidden);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('<title>'.Setting::get('clinic.name').' · Dentistas en Miraflores</title>', false)
            ->assertSee('Atención dental sin esperas')
            // Con 2 horas de anticipación, lo primero libre es hoy a las 10:00, y un toque lo deja elegido.
            ->assertSee('Hoy, lunes 2 de noviembre')
            ->assertSee("10:00\u{A0}a.\u{A0}m.")
            ->assertSee(route('site.booking', ['tratamiento' => $evaluation->slug, 'fecha' => '2026-11-02', 'hora' => '10:00']))
            ->assertSee('desde S/ 50')
            ->assertDontSee('Tratamiento interno')
            ->assertSee($doctor->name)
            ->assertSee('COP '.$doctor->cop_number)
            ->assertSee('Av. Larco 123, Miraflores')
            ->assertSee("Cerrado ahora · abre hoy a las 9:00\u{A0}a.\u{A0}m.")
            // Sin contenido publicado, esas secciones no aparecen ni en el menú.
            ->assertDontSee('Antes y después')
            ->assertDontSee('Opiniones')
            ->assertDontSee('Promociones');
    }

    public function test_only_published_content_reaches_the_web(): void
    {
        Faq::factory()->create(['question' => '¿Atienden a niños?']);
        Faq::factory()->create(['question' => '¿Pregunta en borrador?', 'is_published' => false]);
        Review::factory()->create(['author_name' => 'Rosa Huamán', 'body' => 'Muy buena atención.']);
        Review::factory()->create(['author_name' => 'Opinión Oculta', 'is_published' => false]);
        SmileCase::factory()->create(['title' => 'Blanqueamiento en 2 sesiones']);
        SmileCase::factory()->create(['title' => 'Caso sin publicar', 'is_published' => false]);
        $this->campaign();
        $this->campaign(['name' => 'Promo vieja', 'slug' => 'promo-vieja', 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth()]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('¿Atienden a niños?')
            ->assertDontSee('¿Pregunta en borrador?')
            ->assertSee('Rosa H.')
            ->assertDontSee('Opinión O.')
            ->assertSee('Blanqueamiento en 2 sesiones')
            ->assertDontSee('Caso sin publicar')
            ->assertSee('Limpieza de verano')
            ->assertDontSee('Promo vieja');

        $this->get(route('site.faq'))->assertOk()->assertSee('¿Atienden a niños?')->assertDontSee('¿Pregunta en borrador?');
        $this->get(route('site.reviews'))->assertOk()->assertSee('Muy buena atención.')->assertDontSee('Opinión Oculta');
        $this->get(route('site.cases'))->assertOk()->assertSee('Blanqueamiento en 2 sesiones')->assertDontSee('Caso sin publicar');
        $this->get(route('site.promotions'))->assertOk()->assertSee('Limpieza de verano')->assertDontSee('Promo vieja');
    }

    public function test_a_treatment_that_is_not_booked_online_starts_with_an_evaluation(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $endodontics = Treatment::factory()->clinicOnly()->create([
            'name' => 'Endodoncia',
            'slug' => 'endodoncia',
            'sessions_label' => '1-3',
            'price_from' => 600,
            'description' => "Se hace con **anestesia local**.\n\n<script>alert(1)</script>\n\n[Clic](javascript:alert(1))",
        ]);
        $this->doctorOffering($evaluation, $endodontics);
        Faq::factory()->for($endodontics)->create(['question' => '¿Duele una endodoncia?', 'answer' => 'No, se usa anestesia.']);

        $this->get(route('site.treatment', 'endodoncia'))
            ->assertOk()
            ->assertSee('<title>Endodoncia en Juliaca · '.Setting::get('clinic.name').'</title>', false)
            ->assertSee('desde S/ 600')
            ->assertSee('1 – 3 sesiones')
            ->assertSee('Reservar evaluación')
            ->assertSee(route('site.booking', ['tratamiento' => $evaluation->slug]))
            ->assertSee('Este tratamiento se programa después de una evaluación')
            // Los próximos horarios son de la evaluación, no del tratamiento.
            ->assertSee(route('site.booking', ['tratamiento' => $evaluation->slug, 'fecha' => '2026-11-02', 'hora' => '10:00']))
            ->assertSee('<strong>anestesia local</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('javascript:alert', false)
            ->assertSee('¿Duele una endodoncia?')
            ->assertSee('"@type":"FAQPage"', false);
    }

    public function test_hidden_or_inactive_treatments_have_no_page(): void
    {
        Treatment::factory()->create(['slug' => 'interno', 'is_public' => false]);
        Treatment::factory()->create(['slug' => 'retirado', 'is_active' => false]);

        $this->get(route('site.treatment', 'interno'))->assertNotFound();
        $this->get(route('site.treatment', 'retirado'))->assertNotFound();
        $this->get(route('site.treatment', 'no-existe'))->assertNotFound();
    }

    public function test_a_promotion_with_a_package_shows_what_it_includes_the_savings_and_how_to_pay(): void
    {
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $whitening = Treatment::factory()->create(['name' => 'Blanqueamiento', 'price_from' => 480]);
        $package = TreatmentPackage::factory()->inInstallments(100, 4)->create(['name' => 'Sonrisa en familia', 'price' => 500, 'for_two' => true, 'description' => 'Incluye el control al mes.']);
        $package->items()->create(['treatment_id' => $cleaning->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $package->items()->create(['treatment_id' => $whitening->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 1]);
        $this->campaign(['name' => 'Sonrisa de verano', 'slug' => 'sonrisa-verano', 'summary' => null, 'treatment_package_id' => $package->id]);

        $this->get(route('site.promotions'))->assertOk()->assertSee('2x1 · ahorras S/ 100');

        $this->get(route('site.promotion', 'sonrisa-verano'))
            ->assertOk()
            ->assertSeeInOrder(['Qué incluye', 'Limpieza dental', 'Blanqueamiento', 'Precio normal S/ 600.00', 'S/ 500.00', 'Ahorras S/ 100.00'])
            ->assertSee(['con el mismo pago atendemos también a otra persona', 'Cómo se puede pagar', 'Pago único de S/ 500.00.', 'En cuotas: inicial de S/ 100.00 y 4 cuotas mensuales de S/ 100.00.', 'Incluye el control al mes.'])
            ->assertSee('al firmar el plan de tratamiento');

        // Si el paquete deja de ofrecerse, la promoción sigue sin mostrarlo.
        $package->update(['is_active' => false]);

        $this->get(route('site.promotion', 'sonrisa-verano'))->assertOk()->assertDontSee('Qué incluye');
    }

    public function test_a_campaign_shows_its_price_on_the_treatment_and_the_promotion_and_books_with_it(): void
    {
        $this->freezeMonday();
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'slug' => 'limpieza-dental', 'price_from' => 150]);
        $whitening = Treatment::factory()->create(['name' => 'Blanqueamiento', 'slug' => 'blanqueamiento', 'price_from' => 480]);
        $this->doctorOffering($cleaning, $whitening);
        $this->campaign(['treatment_id' => $cleaning->id, 'discount_type' => DiscountType::Percent, 'discount_value' => 20]);

        $this->get(route('site.treatment', 'limpieza-dental'))
            ->assertOk()
            ->assertSeeInOrder(['Precio con la campaña', 'desde S/ 150', 'desde S/ 120'])
            ->assertSeeInOrder(['Campaña', 'Limpieza de verano', '· hasta el 2 de diciembre.'])
            ->assertSee(['Reservar con la campaña', route('site.booking', ['tratamiento' => 'limpieza-dental', 'promo' => 'limpieza-verano'])])
            ->assertSee('Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.');

        // Otro tratamiento no tiene la campaña.
        $this->get(route('site.treatment', 'blanqueamiento'))
            ->assertOk()
            ->assertDontSee('Precio con la campaña')
            ->assertSee(route('site.booking', ['tratamiento' => 'blanqueamiento']));

        $this->get(route('site.promotion', 'limpieza-verano'))
            ->assertOk()
            ->assertSeeInOrder(['Precio normal desde S/ 150', 'desde S/ 120', 'Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.'])
            ->assertSee(route('site.booking', ['tratamiento' => 'limpieza-dental', 'promo' => 'limpieza-verano', 'origen' => 'campaign']));
    }

    public function test_a_promotion_counts_one_visit_per_person_and_an_ended_one_offers_the_current_ones(): void
    {
        $campaign = $this->campaign();
        $this->campaign(['name' => 'Blanqueamiento de invierno', 'slug' => 'blanqueamiento-invierno', 'starts_at' => now()->subMonths(3), 'ends_at' => now()->subMonth()]);

        $this->get(route('site.promotion', 'limpieza-verano'))->assertOk()->assertSee('Limpieza con 20% de descuento.');
        $this->get(route('site.promotion', 'limpieza-verano'))->assertOk();
        $this->assertSame(1, $campaign->fresh()->views_count);

        $this->get(route('site.promotion', 'blanqueamiento-invierno'))
            ->assertNotFound()
            ->assertSee('Esta promoción ya terminó')
            ->assertSee('Limpieza de verano')
            ->assertSee('<meta name="robots" content="noindex">', false);

        $this->get(route('site.promotion', 'no-existe'))->assertNotFound()->assertSee('No encontramos esta promoción');
    }

    public function test_the_team_and_contact_pages(): void
    {
        $this->freezeMonday();
        Setting::set('clinic.address', 'Av. Larco 123');
        Setting::set('clinic.district', 'Miraflores');
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $doctor->update(['bio' => 'Atiendo a toda la familia.', 'education' => "Universidad de San Martín de Porres\nDiplomado en odontopediatría"]);

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSee($doctor->name)
            ->assertSee('Atiendo a toda la familia.')
            ->assertSee('Diplomado en odontopediatría')
            ->assertSee('Limpieza dental')
            ->assertSee(route('site.booking', ['doctor' => $doctor->slug]));

        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSee('Av. Larco 123, Miraflores')
            ->assertSee('Lunes a sábado')
            ->assertSee("3:00\u{A0}p.\u{A0}m. – 7:00\u{A0}p.\u{A0}m.")
            ->assertSee('https://wa.me/51918874568', false)
            ->assertSee('"@type":"Dentist"', false)
            ->assertSee('"addressLocality":"Miraflores"', false);
    }

    public function test_the_sitemap_lists_public_pages_and_robots_keeps_private_ones_out(): void
    {
        Treatment::factory()->create(['slug' => 'limpieza']);
        Treatment::factory()->create(['slug' => 'interno', 'is_public' => false]);
        $this->campaign();

        $this->get(route('site.sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.route('site.treatment', 'limpieza').'</loc>', false)
            ->assertDontSee(route('site.treatment', 'interno'), false)
            ->assertSee('<loc>'.route('site.promotion', 'limpieza-verano').'</loc>', false)
            ->assertSee('<loc>'.route('site.contact').'</loc>', false)
            // Sin preguntas publicadas, no se anuncia una página vacía.
            ->assertDontSee(route('site.faq'), false);

        $this->get(route('site.robots'))
            ->assertOk()
            ->assertSee('Disallow: /panel')
            ->assertSee('Disallow: /mi-cuenta')
            ->assertSee('Sitemap: '.route('site.sitemap'));
    }
}
