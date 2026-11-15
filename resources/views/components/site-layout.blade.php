{{--
    wide: páginas de contenido (inicio, tratamientos…); sin wide, columna angosta para formularios.
    whatsappFloat: false en pantallas donde el botón flotante (y, en el celular, la barra de abajo) taparía los horarios.
    footerContact: false donde la página ya muestra la dirección, el horario y el WhatsApp ("Dónde estamos"),
        para no repetirlos en el pie: ahí queda solo la lista de páginas. En las demás, el pie trae lo justo para
        ubicarse (dirección, si está abierto y WhatsApp); el horario completo y el mapa están en Contacto.
    minimalFooter: pie de una línea mientras se reserva, para que nada distraiga hasta terminar (el encabezado
        también queda solo con el nombre, que vuelve al inicio).
    jsonLd: datos estructurados de schema.org para Google.
    hero (slot): franja de portada a todo el ancho de la pantalla, antes de la columna del contenido (el inicio).
--}}
@props(['title' => null, 'description' => null, 'noindex' => false, 'whatsappFloat' => true, 'footerContact' => true, 'minimalFooter' => false, 'wide' => false, 'image' => null, 'jsonLd' => []])
@php
    use App\Services\Web\ClinicInfo;
    use App\Support\Phone;
    use App\Support\SiteArt;
    use App\Support\SiteIcon;
    use App\Support\TreatmentIcon;

    $clinic = app(ClinicInfo::class);
    $sections = $clinic->sections();
    $name = $clinic->name();
    $pageTitle = $title ? $title.' · '.$name : $name.($clinic->district() ? ' · Dentistas en '.$clinic->district() : '');
    $description ??= $clinic->intro();
    $hours = $clinic->hours();
    $status = $hours->status(now()->toImmutable());

    // Bajo el nombre, dónde atiende: lo mismo que dice el título de la página en Google.
    $tagline = $clinic->district() ? 'Dentistas en '.$clinic->district() : null;

    $nav = array_filter([
        ['Inicio', route('site.home'), 'site.home'],
        ['Servicios', route('site.treatments'), 'site.treatment*'],
        ['Equipo', route('site.team'), 'site.team'],
        $sections['promotions'] ? ['Promociones', route('site.promotions'), 'site.promotion*'] : null,
        ['Contacto', route('site.contact'), 'site.contact'],
    ]);
    $more = array_values(array_filter([
        $sections['cases'] ? ['Antes y después', route('site.cases'), 'site.cases'] : null,
        $sections['reviews'] ? ['Opiniones', route('site.reviews'), 'site.reviews'] : null,
        $sections['faq'] ? ['Preguntas frecuentes', route('site.faq'), 'site.faq'] : null,
    ]));
    // Al compartir el enlace siempre sale una imagen: la de la página, la foto del consultorio o la de la marca.
    $shareImage = $image ?? $clinic->heroImageUrl() ?? asset('imagen-al-compartir.jpg');
@endphp
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $pageTitle }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($noindex)
        <meta name="robots" content="noindex">
    @else
        <link rel="canonical" href="{{ url()->current() }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:locale" content="es_PE">
    <meta property="og:site_name" content="{{ $name }}">
    <meta property="og:title" content="{{ $title ?? $name }}">
    @if ($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ url($shareImage) }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="{{ asset('fonts/inter-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/poppins-latin-700.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/sitio.css') }}?v={{ @filemtime(public_path('css/sitio.css')) }}">
    {{-- Con JavaScript, las secciones aparecen suaves al llegar a ellas; sin él, se ven de una vez. --}}
    <script>document.documentElement.classList.add('js')</script>
    @foreach ($jsonLd as $data)
        <script type="application/ld+json">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endforeach
</head>
<body>
<a href="#contenido" class="skip">Saltar al contenido</a>
{{-- Con una página extra a la vista el menú necesita más ancho (sitio.css): hasta entonces queda el botón "Menú". --}}
<header @class(['site-header', 'many' => (bool) $more])>
    <div class="wrap">
        @if ($logo = $clinic->logoUrl())
            <a href="{{ route('site.home') }}" class="wordmark with-logo"><img src="{{ $logo }}" alt="{{ $name }}" class="logo"></a>
        @else
            <a href="{{ route('site.home') }}" class="wordmark">
                <span class="mark">{{ TreatmentIcon::svg('tooth') }}</span>
                <span class="brand">{{ $name }}@if ($tagline)<small>{{ $tagline }}</small>@endif</span>
            </a>
        @endif
        @unless ($minimalFooter)
        <nav class="site-nav" aria-label="Principal">
            @foreach ($nav as [$label, $url, $pattern])
                <a href="{{ $url }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>@if ($pattern === 'site.home'){{ SiteIcon::svg('home') }}@endif{{ $label }}</a>
            @endforeach
            {{-- Las demás páginas con contenido: una sola va a la vista; varias, bajo "Más". --}}
            @if (count($more) === 1)
                <a href="{{ $more[0][1] }}" @if (request()->routeIs($more[0][2])) aria-current="page" @endif>{{ $more[0][0] }}</a>
            @elseif ($more)
                <details class="more-nav">
                    <summary @if (request()->routeIs(array_column($more, 2))) aria-current="page" @endif>Más</summary>
                    <div class="more-panel">
                        @foreach ($more as [$label, $url, $pattern])
                            <a href="{{ $url }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </div>
                </details>
            @endif
            <a href="{{ route('site.account') }}" @if (request()->routeIs('site.account*')) aria-current="page" @endif>Mi cuenta</a>
        </nav>
        @unless (request()->routeIs('site.booking'))
            <a href="{{ route('site.booking') }}" class="btn small">{{ SiteIcon::svg('calendar') }}Reservar<span class="long">&nbsp;cita</span></a>
        @endunless
        <details class="menu">
            <summary aria-label="Menú">Menú</summary>
            <nav class="menu-panel" aria-label="Menú">
                @foreach ([...$nav, ...$more] as [$label, $url, $pattern])
                    <a href="{{ $url }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
                <a href="{{ route('site.account') }}" @if (request()->routeIs('site.account*')) aria-current="page" @endif>Mi cuenta</a>
            </nav>
        </details>
        @endunless
    </div>
</header>

@isset($hero)
<main id="contenido" class="with-hero">
    {{-- Los avisos van antes de la portada: debajo de ella quedarían fuera de la pantalla. --}}
    @if (session('status') || session('error'))
        <div class="wrap wide">@include('site.partials.flash')</div>
    @endif
    {{ $hero }}
@else
<main id="contenido">
@endisset
    <div @class(['wrap', 'wide' => $wide])>
        @unless (isset($hero))
            @include('site.partials.flash')
        @endunless

        {{ $slot }}
    </div>
</main>

@if ($minimalFooter)
    <footer class="site-footer minimal">
        <div class="wrap wide">
            <p class="name">{{ $name }}</p>
            @if ($clinic->whatsappUrl())
                <p>¿Alguna duda? <a href="{{ $clinic->whatsappUrl('Hola, tengo una duda con mi reserva.') }}" rel="noopener">Escríbenos por WhatsApp</a></p>
            @endif
            <p><a href="{{ route('site.privacy') }}">Política de privacidad</a></p>
        </div>
    </footer>
@else
<footer @class(['site-footer', 'short' => ! $footerContact])>
    <div class="wrap wide">
        {{-- El cierre de cada página: la invitación a reservar, en una franja que monta sobre el borde del pie. --}}
        <div class="footer-cta">
            <img src="{{ SiteArt::url('diente-320') }}" alt="" width="320" height="320" loading="lazy" decoding="async">
            <p><strong>Tu salud bucal, en buenas manos</strong> Reserva tu cita hoy y luce una sonrisa saludable.</p>
            <a href="{{ route('site.booking') }}" class="btn">Reservar cita{{ SiteIcon::svg('arrow-right') }}</a>
        </div>
        <div class="footer-top">
            <p class="name">
                <span class="mark">{{ TreatmentIcon::svg('tooth') }}</span>
                <span class="brand">{{ $name }}@if ($tagline)<small>{{ $tagline }}</small>@endif</span>
            </p>
        </div>
        <div class="footer-cols">
            {{-- Lo justo para ubicarse: dónde queda, si está abierto y cómo escribir. El horario completo y el mapa están en Contacto. --}}
            @if ($footerContact)
                <div class="footer-contact">
                    <h2>Contacto</h2>
                    @if ($clinic->fullAddress())
                        <p>{{ $clinic->fullAddress() }}@if ($clinic->directionsUrl()) · <a href="{{ $clinic->directionsUrl() }}" rel="noopener" target="_blank">Cómo llegar</a>@endif</p>
                    @endif
                    @unless ($hours->isEmpty())
                        <p>@if ($status)<span @class(['status', 'open' => $hours->isOpen(now()->toImmutable())])>{{ $status }}</span> · @endif<a href="{{ route('site.contact') }}">Ver el horario</a></p>
                    @endunless
                    @if ($clinic->whatsappUrl())
                        <p>WhatsApp: <a href="{{ $clinic->whatsappUrl() }}" rel="noopener">{{ Phone::display($clinic->whatsapp()) }}</a></p>
                    @endif
                    @if ($clinic->phone())
                        <p>Teléfono: <a href="{{ $clinic->phoneHref() }}">{{ $clinic->phone() }}</a></p>
                    @endif
                    @if ($clinic->email())
                        <p><a href="mailto:{{ $clinic->email() }}">{{ $clinic->email() }}</a></p>
                    @endif
                </div>
            @endif
            <nav aria-label="Más páginas">
                <h2>El consultorio</h2>
                <ul>
                    @foreach ([...$nav, ...$more] as [$label, $url])
                        <li><a href="{{ $url }}">{{ $label }}</a></li>
                    @endforeach
                    <li><a href="{{ route('site.account') }}">Mi cuenta</a></li>
                </ul>
                @if ($clinic->socials())
                    <p class="socials">
                        @foreach ($clinic->socials() as $network => $url)
                            <a href="{{ $url }}" rel="noopener" target="_blank" aria-label="{{ $network }}" title="{{ $network }}">{{ SiteIcon::svg(strtolower($network)) }}</a>
                        @endforeach
                    </p>
                @endif
            </nav>
        </div>
        <div class="footer-bottom">
            <p>© {{ now()->year }} {{ $name }}</p>
            <p><a href="{{ route('site.privacy') }}">Política de privacidad</a></p>
        </div>
    </div>
</footer>
@endif

{{-- En el celular: barra fija abajo con lo que más se busca. En pantallas grandes queda el botón de WhatsApp. --}}
@if ($whatsappFloat)
    <nav class="action-bar" aria-label="Acciones rápidas">
        <a href="{{ route('site.booking') }}" class="btn">Reservar<span class="long">&nbsp;cita</span></a>
        @if ($clinic->whatsappUrl())
            <a href="{{ $clinic->whatsappUrl() }}" class="btn whatsapp" rel="noopener">WhatsApp</a>
        @endif
        @if ($clinic->callHref())
            <a href="{{ $clinic->callHref() }}" class="btn quiet">Llamar</a>
        @endif
    </nav>
@endif

@if ($clinic->whatsappUrl() && $whatsappFloat)
    <a href="{{ $clinic->whatsappUrl() }}" class="wa-float" rel="noopener" aria-label="Escríbenos por WhatsApp">
        {{ SiteIcon::svg('whatsapp') }}
    </a>
@endif
<script>
    (function () {
        var sections = document.querySelectorAll('main .section');
        if (!('IntersectionObserver' in window)) {
            sections.forEach(function (el) { el.classList.add('is-in'); });
            return;
        }
        var seen = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    seen.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px' });
        sections.forEach(function (el) { seen.observe(el); });
    })();
    // "Menú" y "Más" se cierran al tocar fuera o con Escape.
    (function () {
        function close(except) {
            document.querySelectorAll('.site-header details[open]').forEach(function (el) {
                if (!except || !el.contains(except)) { el.removeAttribute('open'); }
            });
        }
        document.addEventListener('click', function (event) { close(event.target); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { close(null); } });
    })();
</script>
</body>
</html>
