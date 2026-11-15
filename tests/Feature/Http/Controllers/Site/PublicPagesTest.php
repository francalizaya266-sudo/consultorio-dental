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
use App\Services\Web\ClinicInfo;
use App\Services\Web\PublicImages;
use App\Support\SiteArt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    /** Una capa del implante de la portada tal como sale en la página: la chica para el celular y la grande para lo demás. */
    private function coverLayer(string $name, string $loading): string
    {
        return '<img src="'.SiteArt::url($name.'-640').'" srcset="'.SiteArt::url($name.'-640').' 640w, '.SiteArt::url($name).' 1120w" sizes="(min-width: 900px) 440px, 320px" width="1120" height="1120" alt="" '.$loading.'>';
    }

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
            // Bajo el nombre, dónde atiende; y el título de la portada, con su segunda mitad en celeste y en cursiva.
            ->assertSee('<span class="brand">'.Setting::get('clinic.name').'<small>Dentistas en Miraflores</small></span>', false)
            ->assertSee('<h1 id="titulo">Atención dental <em>sin esperas</em></h1>', false)
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

    public function test_the_home_shows_the_clinic_photo_the_google_stars_and_an_image_per_treatment(): void
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
            // La foto del consultorio llena la portada y el implante de la marca queda sobre ella; cada tratamiento lleva su imagen.
            ->assertSee('<div class="hero-band with-photo">', false)
            ->assertSee('<section class="hero with-photo"', false)
            // Con foto, el implante no se le hace bajar al celular, que no lo muestra: sus dos capas se piden solo donde se ven.
            ->assertSee($this->coverLayer('implante-portada', 'loading="lazy"'), false)
            ->assertSee($this->coverLayer('implante-portada-luz', 'loading="lazy"'), false)
            // Cada tratamiento lleva su imagen en tres tamaños: cada pantalla baja el que le queda justo.
            ->assertSee('<span class="art"><img src="'.SiteArt::url('brackets-192').'" srcset="'.SiteArt::url('brackets-192').' 192w, '.SiteArt::url('brackets-320').' 320w, '.SiteArt::url('brackets').' 640w" sizes="128px" width="128" height="128" alt="" loading="lazy" decoding="async"></span>', false)
            // La dirección, el horario y el WhatsApp salen una vez, en "Dónde estamos": el pie solo lleva las páginas.
            ->assertSeeTextInOrder(['Dónde estamos', 'Lunes a sábado', 'El consultorio', 'Política de privacidad'])
            ->assertSee('site-footer short')
            ->assertDontSee('class="footer-contact"', false);
    }

    public function test_without_the_clinic_photo_the_home_opens_with_the_3d_implant_and_explains_how_to_book(): void
    {
        $this->freezeMonday();
        $this->doctorOffering(Treatment::factory()->evaluation()->create());

        // Lunes 8:00: todavía cerrado, y lo dice sobre el título sin el color de "abierto".
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('<div class="hero-band">', false)
            ->assertSee('<section class="hero with-art"', false)
            // Sin foto, el implante es lo primero que se ve: el implante y, encima, su cinta de luz.
            ->assertSeeInOrder([$this->coverLayer('implante-portada', 'fetchpriority="high"'), $this->coverLayer('implante-portada-luz', 'fetchpriority="high"')], false)
            ->assertSee("<p class=\"open-now\">Cerrado ahora · abre hoy a las 9:00\u{A0}a.\u{A0}m.</p>", false)
            ->assertSeeInOrder(['Horarios libres de verdad', 'Confirmas por WhatsApp', 'Pagas en el consultorio', 'Próximo horario libre'])
            ->assertSeeInOrder(['Reserva en tres pasos', 'Elige día y hora', 'Confirma por WhatsApp', 'Ven a tu cita', 'Yape, Plin, tarjeta o efectivo'])
            ->assertSee([SiteArt::url('calendario'), SiteArt::url('escudo'), SiteArt::url('sonrisa'), SiteArt::url('portada')]);

        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/Lima'));

        $this->get(route('site.home'))->assertSee('<p class="open-now open">Abierto ahora', false);
    }

    public function test_the_phrase_above_the_cover_title_is_the_one_from_the_setup_and_can_be_left_out(): void
    {
        $this->freezeMonday();
        $this->doctorOffering(Treatment::factory()->evaluation()->create());

        // Sin tocar nada sale la frase con que viene el sistema, entre el aviso de si está abierto y el título.
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['class="open-now"', '<p class="eyebrow">Innovación y cuidado dental</p>', '<h1 id="titulo">'], false);

        Setting::set('web.tagline', 'Sonrisas desde 1998');

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('<p class="eyebrow">Sonrisas desde 1998</p>', false)
            ->assertDontSee('Innovación y cuidado dental');

        // Vacía, no deja un renglón en blanco: el aviso de si está abierto se queda.
        Setting::set('web.tagline', null);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertDontSee('class="eyebrow"', false)
            ->assertSee('class="open-now"', false);
    }

    public function test_without_online_booking_the_home_does_not_promise_free_times_or_explain_the_steps(): void
    {
        Treatment::factory()->clinicOnly()->create(['name' => 'Endodoncia', 'slug' => 'endodoncia']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('<section class="hero with-art plain"', false)
            ->assertSee(SiteArt::url('implante-portada'))
            ->assertSee(SiteArt::url('endodoncia-192'))
            // El acceso rápido sigue ahí, sin horarios que ofrecer.
            ->assertSee('Acceso rápido')
            ->assertDontSee('class="trust"', false)
            ->assertDontSee('Horarios libres de verdad')
            ->assertDontSee('Próximo horario libre')
            ->assertDontSee('Reserva en tres pasos');
    }

    public function test_the_cover_title_goes_in_two_tones_whatever_its_length(): void
    {
        // Con un número impar de palabras, la primera mitad se lleva la del medio.
        Setting::set('web.headline', 'Sonrisas sanas siempre');

        $this->get(route('site.home'))->assertOk()->assertSee('<h1 id="titulo">Sonrisas sanas <em>siempre</em></h1>', false);

        // Una sola palabra no tiene segunda mitad que pintar.
        Setting::set('web.headline', 'Sonríe');

        $this->get(route('site.home'))->assertOk()->assertSee('<h1 id="titulo">Sonríe</h1>', false);
    }

    public function test_what_follows_the_comma_in_the_first_half_of_the_cover_title_goes_in_thin_letters(): void
    {
        Setting::set('web.headline', 'Tu sonrisa, nuestra mayor prioridad');

        $this->get(route('site.home'))->assertOk()->assertSee('<h1 id="titulo">Tu sonrisa, <span>nuestra</span> <em>mayor prioridad</em></h1>', false);

        // Si la coma cierra la primera mitad, no queda nada que adelgazar.
        Setting::set('web.headline', 'Tu sonrisa, siempre sana');

        $this->get(route('site.home'))->assertOk()->assertSee('<h1 id="titulo">Tu sonrisa, <em>siempre sana</em></h1>', false);

        // La coma de la segunda mitad no cambia nada: esa parte va entera en celeste.
        Setting::set('web.headline', 'Sonrisas sanas, siempre');

        $this->get(route('site.home'))->assertOk()->assertSee('<h1 id="titulo">Sonrisas sanas, <em>siempre</em></h1>', false);
    }

    public function test_the_home_only_says_prices_are_in_sight_when_some_treatment_shows_one(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create(['show_price' => false]);
        $this->doctorOffering($evaluation);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['class="trust"', 'Horarios libres de verdad', 'Confirmas por WhatsApp', 'Pagas en el consultorio', 'class="start"'], false)
            ->assertDontSee('Precios a la vista')
            // Sin precio que mostrar, la tarjeta del tratamiento dice cuándo se sabe, como texto y no en la pastilla del precio.
            ->assertSee('<span class="price ask">Precio en la evaluación</span>', false);

        $evaluation->update(['show_price' => true, 'price_from' => 50]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['Pagas en el consultorio', 'Precios a la vista', 'class="start"'], false)
            ->assertSee('<span class="price">desde S/ 50</span>', false)
            ->assertDontSee('Precio en la evaluación');
    }

    public function test_the_quick_access_offers_the_next_free_times_booking_whatsapp_and_directions(): void
    {
        $this->freezeMonday();
        Setting::set('clinic.address', 'Av. Larco 123');
        Setting::set('clinic.district', 'Miraflores');
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder([
                'Acceso rápido',
                // Dentro de la tarjeta, el horario libre lleva un título de menor nivel.
                '<h3 id="proximos">Próximo horario libre</h3>',
                route('site.booking', ['tratamiento' => $evaluation->slug, 'fecha' => '2026-11-02', 'hora' => '10:00']),
                'class="quick-actions"',
                'href="'.route('site.booking').'" class="btn light in-bar"',
                'href="https://wa.me/51918874568?text=Hola%2C%20quisiera%20informaci%C3%B3n." class="btn ghost in-bar"',
                'href="https://www.google.com/maps/search/?api=1&amp;query=Av.%20Larco%20123%2C%20Miraflores%2C%20Per%C3%BA" class="btn ghost"',
                'Cómo llegar',
                'Nuestros servicios',
            ], false);

        // Sin WhatsApp ni dirección cargados, quedan solo los horarios y "Reservar cita".
        Setting::set('clinic.whatsapp', null);
        Setting::set('clinic.address', null);
        Setting::set('clinic.district', null);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('href="'.route('site.booking').'" class="btn light in-bar"', false)
            ->assertDontSee('Escribir por WhatsApp')
            ->assertDontSee('Cómo llegar');
    }

    public function test_the_home_shows_the_first_eight_treatments_and_links_to_the_rest(): void
    {
        foreach (range(1, 8) as $number) {
            Treatment::factory()->clinicOnly()->create(['name' => "Tratamiento {$number}", 'slug' => "tratamiento-{$number}", 'sort_order' => $number, 'summary' => "Resumen {$number}."]);
        }

        // Con ocho entran todos: no hace falta mandar a nadie a otra página.
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['<h2 id="servicios">Nuestros servicios</h2>', route('site.treatment', 'tratamiento-1'), 'Tratamiento 1', 'Resumen 1.', route('site.treatment', 'tratamiento-8'), 'Tratamiento 8'], false)
            ->assertDontSee('Ver los 8 servicios');

        Treatment::factory()->clinicOnly()->create(['name' => 'Tratamiento 9', 'slug' => 'tratamiento-9', 'sort_order' => 9]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('<a href="'.route('site.treatments').'" class="arrow">Ver los 9 servicios</a>', false)
            ->assertSee(route('site.treatment', 'tratamiento-8'))
            ->assertDontSee(route('site.treatment', 'tratamiento-9'));
    }

    public function test_on_the_home_a_notice_shows_before_the_cover(): void
    {
        $this->withSession(['status' => '¡Listo! La cita de Rosa quedó confirmada.'])
            ->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['<main id="contenido" class="with-hero">', '<div class="flash ok" role="status">¡Listo! La cita de Rosa quedó confirmada.</div>', '<div class="hero-band">'], false);

        // En las páginas que abren con su franja celeste, el aviso también va antes de ella.
        $this->withSession(['error' => 'Ese enlace ya venció.'])
            ->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['<main id="contenido" class="with-band">', 'Ese enlace ya venció.', '<div class="page-band">', '<h1>Nuestro <em>equipo</em></h1>'], false)
            ->assertDontSee('hero-band');

        // En las demás el aviso sigue arriba del contenido, y no hay portada ni franja.
        $this->withSession(['error' => 'Ese enlace ya venció.'])
            ->get(route('site.privacy'))
            ->assertOk()
            ->assertSeeInOrder(['<main id="contenido">', 'Ese enlace ya venció.', '<h1>'], false)
            ->assertDontSee(['hero-band', 'page-band']);
    }

    public function test_the_header_and_the_footer_carry_the_brand_the_home_link_and_the_social_networks(): void
    {
        Setting::set('clinic.district', null);
        Setting::set('clinic.instagram', 'https://instagram.com/consultorio');
        Setting::set('clinic.tiktok', 'https://tiktok.com/@consultorio');

        // "Inicio" va primero y marca la página en la que se está. Sin distrito, el nombre va solo.
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['class="site-nav"', 'href="'.route('site.home').'"', 'aria-current="page"', 'Inicio</a>', 'href="'.route('site.treatments').'"', 'Servicios</a>', 'class="menu"'], false)
            ->assertSee('<span class="brand">'.Setting::get('clinic.name').'</span>', false)
            ->assertDontSee('Dentistas en');

        // El pie: lo abre la invitación a reservar; debajo, la marca, las redes cargadas como íconos con su nombre, y lo legal al final.
        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['class="site-nav"', 'Inicio</a>', 'aria-current="page"', 'Equipo</a>'], false)
            ->assertSeeInOrder([
                'class="footer-cta"',
                '<img src="'.SiteArt::url('diente-320').'" alt=""',
                'Reserva tu cita con nuestro equipo',
                '<a href="'.route('site.booking').'" class="btn">Reservar cita<svg',
                'class="footer-top"',
                'El consultorio',
                '<a href="https://instagram.com/consultorio" rel="noopener" target="_blank" aria-label="Instagram" title="Instagram"><svg',
                '<a href="https://tiktok.com/@consultorio" rel="noopener" target="_blank" aria-label="TikTok" title="TikTok"><svg',
                'class="footer-bottom"',
                '© '.now()->year.' '.Setting::get('clinic.name'),
                'Política de privacidad',
            ], false)
            ->assertDontSee('aria-label="Facebook"', false);
    }

    public function test_the_home_features_the_treatment_of_the_specialty_a_doctor_declares(): void
    {
        $implant = Treatment::factory()->create(['name' => 'Implante dental', 'slug' => 'implante', 'summary' => 'Reemplaza un diente perdido.']);
        $doctor = $this->doctorOffering($implant, Treatment::factory()->create(['name' => 'Limpieza dental', 'slug' => 'limpieza']));

        // "Cirujano dentista" no es una especialidad: no se destaca nada.
        $doctor->update(['specialty' => 'Cirujano dentista']);

        $this->get(route('site.home'))->assertOk()->assertDontSee('Nuestra especialidad');

        // Un implantólogo destaca el tratamiento de implantes, con su página y su imagen.
        $doctor->update(['specialty' => 'Cirujano dentista · Implantólogo']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder([
                'Nuestra especialidad', '<h2 id="especialidad">Implante dental</h2>', 'Reemplaza un diente perdido.',
                '<a href="'.route('site.treatment', 'implante').'" class="btn">Conocer el tratamiento<svg',
                // La imagen va en grande: lleva también su tamaño de más detalle.
                '<img src="'.SiteArt::url('implante').'" srcset="'.SiteArt::url('implante').' 640w, '.SiteArt::url('implante-960').' 960w"', 'Quién te atiende',
            ], false);

        // Solo las imágenes de especialidad tienen ese tamaño: cualquier otra iría en el suyo.
        $this->assertStringNotContainsString('srcset', (string) SiteArt::feature(Treatment::factory()->make(['name' => 'Limpieza dental', 'slug' => 'limpieza'])));

        // Si el consultorio no publica ese tratamiento, la franja no aparece.
        $implant->update(['is_public' => false]);

        $this->get(route('site.home'))->assertOk()->assertDontSee('Nuestra especialidad');
    }

    public function test_the_home_announces_a_promotion_with_its_photo_or_with_the_smiling_tooth(): void
    {
        $campaign = $this->campaign();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('<a href="'.route('site.promotion', 'limpieza-verano').'" class="promo-band">', false)
            ->assertSeeInOrder(['Promoción', 'Limpieza de verano', 'Limpieza con 20% de descuento.', 'Hasta el', 'Ver la promoción'])
            ->assertSee('<img src="'.SiteArt::url('sonrisa').'" alt="" width="450" height="410" loading="lazy" class="promo-art">', false)
            ->assertDontSee('promo-photo');

        $campaign->update(['image_path' => 'promociones/verano.jpg']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('class="promo-band with-photo"', false)
            ->assertSee('<img src="/media/promociones/verano.jpg" alt="" loading="lazy" class="promo-photo">', false)
            ->assertDontSee('promo-art');

        // Una promoción que no va al inicio no se anuncia ahí.
        $campaign->update(['show_on_home' => false]);

        $this->get(route('site.home'))->assertOk()->assertDontSee('promo-band');
    }

    public function test_the_home_introduces_who_attends_in_brief_and_the_team_page_has_the_rest(): void
    {
        Setting::set('web.about', 'Atendemos a toda la familia desde 2015.');

        // Sin doctores publicados, la presentación del consultorio va sola en el inicio.
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['Sobre '.Setting::get('clinic.name'), 'Atendemos a toda la familia desde 2015.'])
            ->assertDontSee('Quién te atiende');

        // Con un doctor: una tarjeta con su nombre, su especialidad, su colegiatura y lo primero de su presentación.
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $doctor->update(['specialty' => 'Ortodoncia', 'cop_number' => '12345', 'rne_number' => '678', 'bio' => str_repeat('Atiendo con calma. ', 12)]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder([
                '<section class="section meet"', '<p class="kicker">Quién te atiende</p>', '<h2 id="equipo">'.e($doctor->name).'</h2>',
                '<p class="specialty">Ortodoncia</p>', '<p class="credentials">COP 12345 · RNE 678</p>',
                '<p>'.Str::limit($doctor->bio, 160).'</p>',
                '<a href="'.route('site.team').'" class="btn quiet">Conocer al equipo<svg',
            ], false)
            // La presentación del consultorio y el botón para reservar con el doctor están en Equipo: aquí no se repiten.
            ->assertDontSee('Atendemos a toda la familia desde 2015.')
            ->assertDontSee('Sobre '.Setting::get('clinic.name'))
            ->assertDontSee('profesional del equipo')
            ->assertDontSee(route('site.booking', ['doctor' => $doctor->slug]));

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['Nuestro equipo', 'Atendemos a toda la familia desde 2015.', $doctor->name, route('site.booking', ['doctor' => $doctor->slug])])
            ->assertDontSee('con colegiatura en el Colegio Odontológico del Perú');

        // Con varios, el inicio presenta al primero y dice cuántos más hay; a los demás se los conoce en Equipo.
        $second = $this->doctorOffering(Treatment::factory()->create());

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['<p class="kicker">Quiénes te atienden</p>', '<h2 id="equipo">'.e($doctor->name).'</h2>', 'Junto con otro profesional del equipo.'], false)
            ->assertDontSee($second->name);

        $third = $this->doctorOffering(Treatment::factory()->create());
        // Quien va primero en el orden del consultorio es a quien se presenta.
        $third->update(['sort_order' => -1]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['<h2 id="equipo">'.e($third->name).'</h2>', 'Junto con otros 2 profesionales del equipo.'], false)
            ->assertDontSee($doctor->name);
    }

    public function test_the_presentation_of_the_clinic_goes_under_the_band_of_the_team_page(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());

        // Sin presentación, del encabezado se pasa directo a los doctores.
        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['<div class="page-band">', '<h3>'.e($doctor->name).'</h3>'], false)
            ->assertDontSee('class="page-intro', false);

        Setting::set('web.about', 'Atendemos a toda la familia desde 2015.');

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['<div class="page-band">', '<div class="page-intro prose"><p>Atendemos a toda la familia desde 2015.</p></div>', '<h3>'.e($doctor->name).'</h3>'], false);
    }

    public function test_the_home_goes_from_the_services_to_the_specialty_the_steps_who_attends_the_opinions_the_questions_and_the_address(): void
    {
        $this->freezeMonday();
        $implant = Treatment::factory()->evaluation()->create(['name' => 'Implante dental', 'slug' => 'implante']);
        $this->doctorOffering($implant)->update(['specialty' => 'Implantólogo']);
        Review::factory()->create(['author_name' => 'Rosa Huamán']);
        SmileCase::factory()->create(['title' => 'Blanqueamiento en 2 sesiones']);
        Faq::factory()->create(['question' => '¿Atienden a niños?']);
        $this->campaign();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="servicios"', 'class="promo-band"', 'id="especialidad"', 'id="como"', 'id="equipo"', 'id="casos"', 'id="opiniones"',
                // Las preguntas van sobre la franja celeste, antes de la dirección.
                '<section class="section split band" aria-labelledby="preguntas">', 'id="contacto"', 'class="site-footer short"',
            ], false);
    }

    public function test_the_home_shows_the_four_main_questions_and_links_to_all_of_them(): void
    {
        foreach (range(1, 5) as $number) {
            Faq::factory()->create(['question' => "¿Pregunta {$number}?", 'sort_order' => $number]);
        }
        // Las preguntas de un tratamiento van en su página, no entre las principales.
        Faq::factory()->create(['question' => '¿Duele el blanqueamiento?', 'treatment_id' => Treatment::factory()->create()->id, 'sort_order' => 0]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSeeInOrder(['Preguntas frecuentes', '¿Pregunta 1?', '¿Pregunta 4?', '<a href="'.route('site.faq').'" class="arrow">Ver todas las preguntas</a>'], false)
            ->assertDontSee('¿Pregunta 5?')
            ->assertDontSee('¿Duele el blanqueamiento?');

        $this->get(route('site.faq'))->assertOk()->assertSee(['¿Pregunta 5?', '¿Duele el blanqueamiento?']);
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
            // En el resto de páginas el pie trae lo justo para ubicarse y todo el menú; el horario completo está en Contacto.
            ->assertSeeInOrder(['class="footer-contact"', '<a href="'.route('site.contact').'">Ver el horario</a>', 'WhatsApp: <a href="https://wa.me/51918874568?', 'Teléfono: <a href="tel:051321234">(051) 32-1234</a>', 'El consultorio'], false)
            ->assertDontSee('Lunes a sábado');

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
            ->assertDontSee('class="footer-contact"', false)
            // Ya está reservando: el pie no lo vuelve a invitar.
            ->assertDontSee('class="footer-cta"', false);
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
            // Arriba, el camino de vuelta a la lista: al paciente se le dice "servicios".
            ->assertSee('<a href="'.route('site.treatments').'">Servicios</a></nav>', false)
            // Su imagen va arriba del nombre, y se carga de inmediato porque está a la vista.
            ->assertSee('<span class="treatment-art"><img src="'.SiteArt::url('endodoncia-192').'" srcset="'.SiteArt::url('endodoncia-192').' 192w, '.SiteArt::url('endodoncia-320').' 320w, '.SiteArt::url('endodoncia').' 640w" sizes="112px" width="112" height="112" alt="" decoding="async"></span>', false)
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
        $this->doctorOffering(Treatment::factory()->create(['name' => 'Limpieza dental', 'slug' => 'limpieza']));

        // Sin evaluación en la web, el botón lleva a elegir y lo dice. Cada tratamiento lleva su imagen, y la invitación, la de la evaluación.
        $this->get(route('site.treatments'))
            ->assertOk()
            // Al paciente se le habla de "servicios", en el título de la página y en el de la pestaña.
            ->assertSee(['<title>Nuestros servicios · ', '<h1>Nuestros <em>servicios</em></h1>'], false)
            ->assertSee('<span class="icon"><img src="'.SiteArt::url('limpieza-192').'"', false)
            ->assertSee('<img src="'.SiteArt::url('evaluacion').'" alt="" width="640" height="640" loading="lazy">', false)
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
            // La página abre con su franja; la dirección y el horario van en la tarjeta "Nuestra ubicación".
            ->assertSeeInOrder(['<title>Contacto y cómo llegar · ', '<h1>Contácta<em>nos</em></h1>', '<h2 id="contacto"><span class="badge"><svg', '</span>Nuestra ubicación</h2>', '<p class="address">Av. Larco 123, Miraflores</p>', 'Horario de atención'], false)
            ->assertSee('Lunes a sábado')
            ->assertSee("3:00\u{A0}p.\u{A0}m. – 7:00\u{A0}p.\u{A0}m.")
            // "Cómo llegar" sale una vez, bajo el mapa, y el pie no repite la dirección: lo abre la invitación a reservar.
            ->assertSeeInOrder(['class="map"', '>Cómo llegar</a>', 'class="footer-cta"', 'Reservar cita<svg'], false)
            ->assertDontSee('class="footer-contact"', false)
            // El correo, que antes solo estaba en el pie, no se pierde.
            ->assertSee('href="mailto:citas@consultorio.test"', false)
            ->assertSee('https://wa.me/51918874568', false)
            ->assertSee('"@type":"Dentist"', false)
            ->assertSee('"addressLocality":"Miraflores"', false);
    }

    public function test_each_service_goes_in_a_card_with_its_price_what_it_is_for_and_the_way_to_its_page(): void
    {
        Treatment::factory()->create(['name' => 'Limpieza dental', 'slug' => 'limpieza', 'summary' => 'Quita el sarro y las manchas.', 'price_from' => 80, 'duration_minutes' => 45, 'sessions_label' => '1', 'sort_order' => 1]);
        Treatment::factory()->create(['name' => 'Implante dental', 'slug' => 'implante', 'summary' => null, 'show_price' => false, 'duration_minutes' => 60, 'sessions_label' => null, 'sort_order' => 2]);

        $response = $this->get(route('site.treatments'));

        $response->assertOk()
            ->assertSeeInOrder([
                '<a href="'.route('site.treatment', 'limpieza').'">',
                '<span class="name">Limpieza dental</span>', '<span class="price">desde S/ 80</span>', '<span class="desc">Quita el sarro y las manchas.</span>',
                '<span class="meta">45 min aprox. · 1 sesión</span>', '<span class="more">Saber más<svg',
                // Sin precio a la vista, la tarjeta dice dónde se sabe; y sin resumen, no deja un hueco.
                '<a href="'.route('site.treatment', 'implante').'">',
                '<span class="name">Implante dental</span>', '<span class="price ask">Precio en la evaluación</span>',
                '<span class="meta">60 min aprox.</span>', '<span class="more">Saber más<svg',
            ], false);

        $this->assertSame(1, substr_count($response->getContent(), 'class="desc"'));
    }

    public function test_on_the_team_page_each_doctor_goes_in_a_card_with_the_training_what_they_attend_and_the_button_to_book(): void
    {
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'slug' => 'limpieza']);
        $hidden = Treatment::factory()->create(['name' => 'Tratamiento interno', 'is_public' => false]);
        $doctor = $this->doctorOffering($cleaning, $hidden);
        $doctor->update(['specialty' => 'Ortodoncia', 'cop_number' => '12345', 'bio' => 'Atiendo a toda la familia.', 'education' => "Universidad de San Martín de Porres\n\nDiplomado en odontopediatría"]);

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder([
                '<div class="team full">', '<h3>'.e($doctor->name).'</h3>', '<p class="specialty">Ortodoncia</p>', 'COP 12345',
                '<div class="about">', '<p>Atiendo a toda la familia.</p>',
                '<a href="'.route('site.booking', ['doctor' => $doctor->slug]).'" class="btn small" aria-label="Reservar cita con '.e($doctor->name).'"><svg', 'Reservar cita</a>',
                '<div class="more">', '<h4>Formación</h4>', '<li>Universidad de San Martín de Porres</li>', '<li>Diplomado en odontopediatría</li>',
                '<h4>Atiende</h4>', '<a href="'.route('site.treatment', 'limpieza').'">Limpieza dental</a>',
            ], false)
            // Un renglón en blanco de la formación no deja un punto vacío, y lo que no es público no se anuncia.
            ->assertDontSee('<li></li>', false)
            ->assertDontSee('Tratamiento interno');

        // Quien no recibe reservas en la web no lleva el botón; sin formación ni servicios, su tarjeta no deja esa parte vacía.
        $doctor->update(['accepts_online_booking' => false, 'education' => null]);
        $doctor->treatments()->detach();

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSee('<p>Atiendo a toda la familia.</p>', false)
            ->assertDontSee(route('site.booking', ['doctor' => $doctor->slug]))
            ->assertDontSee(['<div class="more">', '<h4>Formación</h4>', '<h4>Atiende</h4>'], false);
    }

    public function test_the_team_page_closes_with_what_distinguishes_the_team_as_written_in_the_panel(): void
    {
        // Sin doctores publicados no hay a quién distinguir.
        $this->get(route('site.team'))->assertOk()->assertDontSee('class="values"', false);

        $doctor = $this->doctorOffering(Treatment::factory()->create());

        // Con lo que trae el sistema: cuatro puntos, cada uno con su ícono, bajo los doctores.
        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder([
                '<h3>'.e($doctor->name).'</h3>', '<ul class="values" aria-label="Lo que nos distingue">',
                '<strong>Colegiados</strong><span>Cada doctor con su número de colegiatura</span>',
                '<strong>Te explicamos todo</strong><span>El presupuesto, antes de empezar</span>',
                '<strong>Compromiso</strong><span>Con tu salud bucal</span>',
                '<strong>Trato humano</strong><span>Tu bienestar es primero</span>',
            ], false);

        // Un punto sin título no sale aunque tenga texto, y uno sin texto sale solo con su título.
        Setting::set('web.pages.equipo.value1_title', 'Atención en quechua');
        Setting::set('web.pages.equipo.value2_title', null);
        Setting::set('web.pages.equipo.value3_text', null);

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['<strong>Atención en quechua</strong><span>Cada doctor con su número de colegiatura</span>', '<strong>Compromiso</strong></li>', '<strong>Trato humano</strong>'], false)
            ->assertDontSee(['Te explicamos todo', 'El presupuesto, antes de empezar', 'Con tu salud bucal']);

        // Sin ningún punto, la franja no sale.
        foreach ([1, 3, 4] as $number) {
            Setting::set("web.pages.equipo.value{$number}_title", null);
        }

        $this->get(route('site.team'))->assertOk()->assertSee($doctor->name)->assertDontSee('class="values"', false);
    }

    public function test_the_contact_card_gives_each_way_to_reach_the_clinic_its_own_button(): void
    {
        Setting::set('clinic.address', 'Av. Larco 123');
        Setting::set('clinic.district', 'Miraflores');

        // Sin teléfono fijo ni correo: se escribe y se llama al celular del WhatsApp, y "Cómo llegar" va al pie del mapa.
        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSeeInOrder([
                '<ul class="ways">',
                '<strong>WhatsApp</strong><span>918 874 568</span>', 'class="pill" rel="noopener" aria-label="Escribir por WhatsApp">Escribir</a>',
                '<strong>Teléfono</strong><span>918 874 568</span>', '<a href="tel:+51918874568" class="pill" aria-label="Llamar al consultorio">Llamar</a>',
                '<div class="map-block">', '<div class="map-foot">',
                '<strong>'.e(Setting::get('clinic.name')).'</strong><span>Av. Larco 123, Miraflores</span>', 'target="_blank">Cómo llegar</a>',
            ], false)
            ->assertDontSee(['<strong>Correo</strong>', '<strong>Cómo llegar</strong>'], false);

        // Con teléfono fijo y correo, cada uno sale con el suyo.
        Setting::set('clinic.phone', '(051) 32 1234');
        Setting::set('clinic.email', 'citas@consultorio.test');

        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>WhatsApp</strong><span>918 874 568</span>',
                '<strong>Teléfono</strong><span>(051) 32 1234</span>', '<a href="tel:051321234" class="pill" aria-label="Llamar al consultorio">Llamar</a>',
                '<strong>Correo</strong><span>citas@consultorio.test</span>', '<a href="mailto:citas@consultorio.test" class="pill">Enviar correo</a>',
            ], false);

        // Sin WhatsApp no se ofrece escribir, y se llama al fijo.
        Setting::set('clinic.whatsapp', null);

        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSee('<a href="tel:051321234" class="pill"', false)
            ->assertDontSee(['<strong>WhatsApp</strong>', 'wa.me'], false);
    }

    public function test_without_an_address_the_contact_card_has_no_map_and_the_link_of_the_clinic_goes_with_the_buttons(): void
    {
        Setting::set('clinic.address', null);
        Setting::set('clinic.district', null);

        // Sin dirección ni enlace no hay a dónde llevar: ni mapa ni "Cómo llegar".
        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSee('<strong>WhatsApp</strong>', false)
            ->assertDontSee(['class="map-block"', 'class="address"', 'Cómo llegar</a>'], false);

        Setting::set('clinic.maps_url', 'https://maps.app.goo.gl/consultorio');

        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSeeInOrder(['<strong>Cómo llegar</strong><span>En Google Maps</span>', '<a href="https://maps.app.goo.gl/consultorio" class="pill" rel="noopener" target="_blank">Cómo llegar</a>'], false)
            ->assertDontSee('class="map-block"', false);
    }

    /**
     * Cada página de contenido con la frase corta y el título con que viene el sistema.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function pagesWithBand(): array
    {
        return [
            'servicios' => ['site.treatments', 'Tu sonrisa, nuestra prioridad', '<h1>Nuestros <em>servicios</em></h1>', 'Nuestros servicios'],
            'equipo' => ['site.team', 'Profesionales comprometidos con tu salud', '<h1>Nuestro <em>equipo</em></h1>', 'Nuestro equipo'],
            // Lo que va entre asteriscos es lo que sale en azul, aunque sea media palabra.
            'contacto' => ['site.contact', 'Estamos aquí para ayudarte', '<h1>Contácta<em>nos</em></h1>', 'Contacto y cómo llegar'],
            'preguntas' => ['site.faq', 'Resolvemos tus dudas', '<h1>Preguntas <em>frecuentes</em></h1>', 'Preguntas frecuentes'],
            // Un título de una sola palabra va entero en azul oscuro.
            'promociones' => ['site.promotions', 'Ofertas vigentes', '<h1>Promociones</h1>', 'Promociones'],
            'casos' => ['site.cases', 'Resultados reales', '<h1>Antes y <em>después</em></h1>', 'Antes y después'],
            'opiniones' => ['site.reviews', 'Lo que dicen nuestros pacientes', '<h1>Opiniones de <em>pacientes</em></h1>', 'Opiniones de pacientes'],
        ];
    }

    #[DataProvider('pagesWithBand')]
    public function test_each_content_page_opens_with_its_band(string $route, string $kicker, string $heading, string $tabTitle): void
    {
        $this->get(route($route))
            ->assertOk()
            ->assertSeeInOrder([
                '<title>'.$tabTitle.' · ',
                '<main id="contenido" class="with-band">',
                '<div class="page-band">',
                '<p class="kicker"><span><svg',
                '</span>'.$kicker.'</p>',
                $heading,
                '<p class="lead">',
                '<img src="'.SiteArt::url('cristal-diente-360').'" srcset="'.SiteArt::url('cristal-diente-360').' 360w, '.SiteArt::url('cristal-diente').' 720w"',
            ], false)
            // Sin foto de la página ni del inicio, la franja va sin foto.
            ->assertDontSee('band-photo');
    }

    public function test_the_band_of_a_page_shows_the_texts_saved_in_the_panel(): void
    {
        Setting::set('web.pages.servicios.kicker', 'Más de 20 años en Juliaca');
        Setting::set('web.pages.servicios.title', 'Tratamientos *dentales* para todos');
        Setting::set('web.pages.servicios.lead', 'Elige el tuyo y reserva.');

        // El título va también en la pestaña, sin los asteriscos que marcan lo que sale en azul.
        $this->get(route('site.treatments'))
            ->assertOk()
            ->assertSeeInOrder([
                '<title>Tratamientos dentales para todos · ',
                '</span>Más de 20 años en Juliaca</p>',
                '<h1>Tratamientos <em>dentales</em> para todos</h1>',
                '<p class="lead">Elige el tuyo y reserva.</p>',
            ], false)
            ->assertDontSee('Nuestros servicios');

        // Vacías, la frase corta y el texto no salen; el título vuelve al que trae el sistema.
        Setting::set('web.pages.servicios.kicker', null);
        Setting::set('web.pages.servicios.title', null);
        Setting::set('web.pages.servicios.lead', null);

        $this->get(route('site.treatments'))
            ->assertOk()
            ->assertSee('<h1>Nuestros <em>servicios</em></h1>', false)
            ->assertDontSee(['class="kicker"', '<p class="lead">'], false);
    }

    public function test_the_band_uses_the_photo_of_the_page_or_else_the_one_of_the_home(): void
    {
        Setting::set('web.hero_image', 'inicio/consultorio.jpg');

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSeeInOrder(['<div class="page-band with-photo">', '<figure class="band-photo"><img src="'.PublicImages::url('inicio/consultorio.jpg').'" alt="" fetchpriority="high"></figure>', '<h1>Nuestro <em>equipo</em></h1>'], false);

        Setting::set('web.pages.equipo.photo', 'paginas/equipo.jpg');

        $this->get(route('site.team'))
            ->assertOk()
            ->assertSee('<figure class="band-photo"><img src="'.PublicImages::url('paginas/equipo.jpg').'"', false)
            ->assertDontSee('<figure class="band-photo"><img src="'.PublicImages::url('inicio/consultorio.jpg').'"', false);

        // La foto de una página no pasa a las demás.
        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSee('<figure class="band-photo"><img src="'.PublicImages::url('inicio/consultorio.jpg').'"', false);
    }

    public function test_each_page_closes_with_its_own_phrase_or_with_the_general_one(): void
    {
        $book = '<a href="'.route('site.booking').'" class="btn">Reservar cita<svg';

        $this->get(route('site.treatments'))
            ->assertOk()
            ->assertSeeInOrder(['class="footer-cta"', '<strong>¿Listo para cuidar tu sonrisa?</strong> Reserva tu cita en línea y te confirmamos por WhatsApp.</p>', $book], false);

        // Quien no encontró su duda escribe por WhatsApp desde el cierre de la página.
        $this->get(route('site.faq'))
            ->assertOk()
            ->assertSeeInOrder([
                'class="footer-cta"',
                '<strong>¿No encontraste tu respuesta?</strong> Escríbenos y con gusto te ayudamos.</p>',
                '<a href="'.e(app(ClinicInfo::class)->whatsappUrl('Hola, tengo una pregunta.')).'" class="btn" rel="noopener"><svg',
                'Escribir por WhatsApp</a>',
            ], false)
            ->assertDontSee($book, false);

        // El inicio y las páginas sin cierre propio llevan la frase general, que también se cambia en el panel.
        Setting::set('web.pages.inicio.closing_title', 'Te esperamos');
        Setting::set('web.pages.inicio.closing_text', null);

        foreach (['site.home', 'site.promotions', 'site.privacy'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSeeInOrder(['class="footer-cta"', '<strong>Te esperamos</strong> </p>', $book], false)
                ->assertDontSee('Tu salud bucal, en buenas manos');
        }

        $this->get(route('site.treatments'))->assertOk()->assertSee('¿Listo para cuidar tu sonrisa?')->assertDontSee('Te esperamos');

        // Sin título guardado, el cierre no queda mudo: vuelve el que trae el sistema.
        Setting::set('web.pages.inicio.closing_title', null);

        $this->get(route('site.home'))->assertOk()->assertSee('<strong>Tu salud bucal, en buenas manos</strong>', false);
    }

    public function test_without_whatsapp_the_questions_page_closes_inviting_to_book(): void
    {
        Setting::set('clinic.whatsapp', null);

        $this->get(route('site.faq'))
            ->assertOk()
            ->assertSeeInOrder(['class="footer-cta"', '<strong>Tu salud bucal, en buenas manos</strong>', '<a href="'.route('site.booking').'" class="btn">Reservar cita<svg'], false)
            ->assertDontSee(['¿No encontraste tu respuesta?', 'Escribir por WhatsApp']);
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
