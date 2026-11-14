<?php

namespace Tests\Feature\Http\Controllers\Site;

use App\Enums\DiscountType;
use App\Livewire\Site\BookingWizard;
use App\Models\Campaign;
use App\Models\Faq;
use App\Models\Review;
use App\Models\Setting;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
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

    public function test_the_home_shows_the_clinic_photo_the_google_stars_and_treatment_icons(): void
    {
        $this->freezeMonday();
        Setting::set('web.hero_image', 'inicio/consultorio.jpg');
        Setting::set('clinic.google_rating', 4.9);
        Setting::set('clinic.google_reviews_count', 87);
        Setting::set('clinic.reviews_url', 'https://g.page/r/consultorio/review');
        $this->doctorOffering(Treatment::factory()->create(['name' => 'Ortodoncia con brackets']));

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('<img src="/media/inicio/consultorio.jpg"', false)
            ->assertSee('<meta property="og:image" content="'.url('/media/inicio/consultorio.jpg').'">', false)
            ->assertSee('<strong>4,9</strong> de 5 en Google · 87 opiniones', false)
            ->assertSee('href="https://g.page/r/consultorio/review"', false)
            ->assertSee('style="--rating: 4.9"', false)
            ->assertSee('<span class="icon"><svg', false)
            // La dirección, el horario y el WhatsApp salen una vez, en "Dónde estamos": el pie solo lleva las páginas.
            ->assertSeeTextInOrder(['Dónde estamos', 'Lunes a sábado', 'El consultorio', 'Política de privacidad'])
            ->assertSee('site-footer short')
            ->assertDontSee('<h2>Dónde estamos</h2>', false)
            ->assertDontSee('<h2>Horario</h2>', false)
            ->assertDontSee('<h2>Escríbenos</h2>', false);
    }

    public function test_the_home_presents_the_clinic_and_who_attends_in_one_section(): void
    {
        Setting::set('web.about', 'Atendemos a toda la familia desde 2015.');

        // Sin doctores publicados, la presentación va sola.
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['Sobre '.Setting::get('clinic.name'), 'Atendemos a toda la familia desde 2015.'])
            ->assertDontSee('Quién te atiende');

        // Con un doctor, van juntos, y su botón no repite el "Reservar cita" de la página.
        $doctor = $this->doctorOffering(Treatment::factory()->create());

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['Quién te atiende', 'Atendemos a toda la familia desde 2015.', $doctor->name])
            ->assertDontSee('Sobre '.Setting::get('clinic.name'))
            ->assertDontSee('Con su número de colegiatura (COP) a la vista.')
            ->assertDontSee(route('site.booking', ['doctor' => $doctor->slug]));

        // Con varios doctores, el botón de cada uno sirve para elegir con quién atenderse.
        $other = $this->doctorOffering(Treatment::factory()->create());

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Quiénes te atienden')
            ->assertSee([route('site.booking', ['doctor' => $doctor->slug]), route('site.booking', ['doctor' => $other->slug])]);
    }

    public function test_the_tab_icon_and_the_image_when_sharing_are_always_there(): void
    {
        $treatment = Treatment::factory()->create(['slug' => 'limpieza', 'image_path' => 'tratamientos/limpieza.jpg']);
        Treatment::factory()->create(['slug' => 'resina']);

        foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'imagen-al-compartir.jpg'] as $file) {
            $this->assertGreaterThan(0, filesize(public_path($file)), $file);
        }

        // Sin foto propia, la página se comparte con la imagen de la marca.
        $this->get(route('site.treatment', 'resina'))
            ->assertOk()
            ->assertSee('<link rel="icon" href="'.asset('favicon.svg').'" type="image/svg+xml">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.asset('apple-touch-icon.png').'">', false)
            ->assertSee('<meta property="og:image" content="'.asset('imagen-al-compartir.jpg').'">', false);

        // Con la foto del consultorio subida, esa; y la foto propia de la página le gana a las dos.
        Setting::set('web.hero_image', 'inicio/consultorio.jpg');

        $this->get(route('site.treatment', 'resina'))->assertSee('<meta property="og:image" content="'.url('/media/inicio/consultorio.jpg').'">', false);
        $this->get(route('site.treatment', $treatment->slug))->assertSee('<meta property="og:image" content="'.url('/media/tratamientos/limpieza.jpg').'">', false);
    }

    public function test_the_computer_menu_shows_the_extra_pages_that_have_content(): void
    {
        // Solo preguntas: van a la vista, entre Contacto y Mi cuenta.
        Faq::factory()->create();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['class="site-nav"', 'Contacto', route('site.faq'), 'Preguntas frecuentes', 'Mi cuenta', 'class="menu"'], false)
            ->assertDontSee('more-nav');

        // Varias páginas extra: bajo "Más", para que el menú no se desborde.
        Cache::forget('web.secciones');
        Review::factory()->create();

        $this->get(route('site.faq'))
            ->assertOk()
            ->assertSeeInOrder(['class="site-nav"', 'class="more-nav"', 'aria-current="page"', 'Más</summary>', route('site.reviews'), route('site.faq'), 'Mi cuenta', 'class="menu"'], false);
    }

    public function test_without_photo_or_score_the_home_shows_no_empty_frame_or_stars(): void
    {
        $this->get(route('site.home'))
            ->assertOk()
            ->assertDontSee('hero-photo')
            ->assertDontSee('rating-stars');
    }

    public function test_on_the_phone_reserve_whatsapp_and_call_stay_at_hand_except_while_booking(): void
    {
        $this->doctorOffering(Treatment::factory()->create());
        Setting::set('clinic.phone', '(051) 32-1234');

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['class="action-bar"', route('site.booking'), 'https://wa.me/51918874568', 'href="tel:051321234"'], false)
            // En el resto de páginas el pie trae el horario y todo el menú.
            ->assertSee('<h2>Horario</h2>', false)
            ->assertSee('El consultorio');

        // Sin teléfono fijo, "Llamar" marca el celular del consultorio.
        Setting::set('clinic.phone', null);
        $this->get(route('site.team'))->assertSee('href="tel:+51918874568"', false);

        // Mientras reserva: sin barra ni botón flotante, y un pie de una línea.
        $this->get(route('site.booking'))
            ->assertOk()
            ->assertDontSee('class="action-bar"', false)
            ->assertDontSee('wa-float')
            ->assertSee('site-footer minimal')
            ->assertSee('Escríbenos por WhatsApp')
            ->assertSee('Política de privacidad')
            ->assertDontSee('El consultorio')
            ->assertDontSee('<h2>Horario</h2>', false);
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
            // Se dice antes del botón, y es el único botón de reserva de la página: el del doctor no lo repite.
            ->assertSeeInOrder(['Empieza con una evaluación', 'Reservar evaluación'])
            ->assertSee(route('site.booking', ['tratamiento' => $evaluation->slug]))
            ->assertDontSee(route('site.booking', ['doctor' => $endodontics->doctors->first()->slug]))
            // Los próximos horarios son de la evaluación, no del tratamiento, y el título lo dice.
            ->assertSee('Próximo horario para tu evaluación')
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
            ->assertSeeInOrder(['Precio con la promoción', 'desde S/ 150', 'desde S/ 120'])
            ->assertSeeInOrder(['Promoción', 'Limpieza de verano', '· hasta el 2 de diciembre.'])
            ->assertSee(['Reservar con la promoción', route('site.booking', ['tratamiento' => 'limpieza-dental', 'promo' => 'limpieza-verano'])])
            // Al paciente siempre se le dice "promoción"; "campaña" es la palabra del panel.
            ->assertDontSee('campaña')
            ->assertDontSee('Próximo horario para tu evaluación')
            ->assertSee('Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.');

        // Otro tratamiento no tiene la campaña.
        $this->get(route('site.treatment', 'blanqueamiento'))
            ->assertOk()
            ->assertDontSee('Precio con la promoción')
            ->assertSee(route('site.booking', ['tratamiento' => 'blanqueamiento']));

        $this->get(route('site.promotion', 'limpieza-verano'))
            ->assertOk()
            ->assertSeeInOrder(['Precio normal desde S/ 150', 'desde S/ 120', 'Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.'])
            ->assertSee(['Reservar con la promoción', route('site.booking', ['tratamiento' => 'limpieza-dental', 'promo' => 'limpieza-verano', 'origen' => 'campaign'])])
            ->assertSee("20\u{00A0}% de descuento");
    }

    public function test_a_promotion_whose_name_already_says_the_discount_does_not_repeat_it(): void
    {
        $this->campaign(['name' => 'Blanqueamiento 20% de descuento', 'slug' => 'blanqueamiento-20', 'summary' => null, 'discount_type' => DiscountType::Percent, 'discount_value' => 20]);
        $this->campaign(['name' => 'Limpieza a S/ 50', 'slug' => 'limpieza-50', 'summary' => null, 'discount_type' => DiscountType::Amount, 'discount_value' => 50]);
        $this->campaign(['name' => 'S/ 50 de descuento en resinas', 'slug' => 'resinas-50', 'summary' => null, 'discount_type' => DiscountType::Amount, 'discount_value' => 50]);

        $this->get(route('site.promotion', 'blanqueamiento-20'))
            ->assertOk()
            ->assertSee('Blanqueamiento 20% de descuento')
            ->assertDontSee('discount-big')
            // Quien comparte el enlace sí ve el descuento en la descripción.
            ->assertSee("<meta name=\"description\" content=\"20\u{00A0}% de descuento · Hasta el", false);

        // Un monto en el nombre puede ser el precio y no el descuento: solo se quita si el nombre dice "descuento".
        $this->get(route('site.promotion', 'limpieza-50'))->assertOk()->assertSee('<p class="discount-big">S/ 50 de descuento</p>', false);
        $this->get(route('site.promotion', 'resinas-50'))->assertOk()->assertDontSee('discount-big');

        $this->get(route('site.promotions'))
            ->assertOk()
            ->assertSee('<span class="discount">S/ 50 de descuento</span>', false)
            ->assertDontSee("20\u{00A0}% de descuento");
    }

    public function test_the_treatments_page_books_the_evaluation_when_the_web_offers_it(): void
    {
        $this->doctorOffering(Treatment::factory()->create(['slug' => 'limpieza']));

        // Sin evaluación en la web, el botón lleva a elegir y lo dice.
        $this->get(route('site.treatments'))
            ->assertOk()
            ->assertSee('<a href="'.route('site.booking').'" class="btn">Reservar cita</a>', false)
            ->assertDontSee('Reservar evaluación');

        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);

        $this->get(route('site.treatments'))
            ->assertOk()
            ->assertSee('<a href="'.route('site.booking', ['tratamiento' => $evaluation->slug]).'" class="btn">Reservar evaluación</a>', false);
    }

    public function test_a_promotion_for_a_treatment_that_is_not_booked_online_books_the_evaluation_with_it(): void
    {
        $this->freezeMonday();
        $whitening = Treatment::factory()->clinicOnly()->create(['name' => 'Blanqueamiento', 'slug' => 'blanqueamiento', 'price_from' => 480]);
        $evaluation = Treatment::factory()->evaluation()->create(['name' => 'Evaluación dental', 'slug' => 'evaluacion']);
        $this->doctorOffering($whitening, $evaluation);
        $this->campaign(['name' => 'Blanqueamiento de verano', 'slug' => 'blanqueamiento-verano', 'treatment_id' => $whitening->id, 'discount_type' => DiscountType::Percent, 'discount_value' => 20]);

        $this->get(route('site.promotion', 'blanqueamiento-verano'))
            ->assertOk()
            ->assertSee('Blanqueamiento se programa después de una evaluación: reservas la evaluación y la promoción queda guardada para tu tratamiento.')
            ->assertSee('Reservar evaluación con la promoción')
            ->assertSee(route('site.booking', ['tratamiento' => 'evaluacion', 'promo' => 'blanqueamiento-verano', 'origen' => 'campaign']));

        // En la reserva la promoción se ve desde el primer paso, con la evaluación ya elegida.
        Livewire::withQueryParams(['promo' => 'blanqueamiento-verano'])
            ->test(BookingWizard::class)
            ->assertSet('treatmentId', $evaluation->id)
            ->assertSee("Reservas con la promoción Blanqueamiento de verano · 20\u{00A0}% de descuento.")
            ->assertSee('Se aplica a Blanqueamiento: el doctor lo revisa en esta cita y lo programa contigo.');
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
        Setting::set('clinic.email', 'citas@consultorio.test');

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
            ->assertSee('<h1 id="contacto">Contacto</h1>', false)
            ->assertSee('Lunes a sábado')
            ->assertSee("3:00\u{A0}p.\u{A0}m. – 7:00\u{A0}p.\u{A0}m.")
            // "Cómo llegar" sale una vez, bajo el mapa, y el pie no repite la dirección.
            ->assertSeeInOrder(['class="map"', '>Cómo llegar</a>'], false)
            ->assertDontSee('<h2>Dónde estamos</h2>', false)
            // El correo, que antes solo estaba en el pie, no se pierde.
            ->assertSee('href="mailto:citas@consultorio.test"', false)
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
