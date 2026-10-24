{{--
    wide: páginas de contenido (inicio, tratamientos…); sin wide, columna angosta para formularios.
    whatsappFloat: false en pantallas donde el botón flotante (y, en el celular, la barra de abajo) taparía los horarios.
    footerHours: false donde la página ya muestra el horario completo, para no repetirlo en el pie.
    minimalFooter: pie de una línea mientras se reserva, para que nada distraiga hasta terminar.
    jsonLd: datos estructurados de schema.org para Google.
--}}
@props(['title' => null, 'description' => null, 'noindex' => false, 'whatsappFloat' => true, 'footerHours' => true, 'minimalFooter' => false, 'wide' => false, 'image' => null, 'jsonLd' => []])
@php
    use App\Services\Web\ClinicInfo;
    use App\Support\Phone;

    $clinic = app(ClinicInfo::class);
    $sections = $clinic->sections();
    $name = $clinic->name();
    $pageTitle = $title ? $title.' · '.$name : $name.($clinic->district() ? ' · Dentistas en '.$clinic->district() : '');
    $description ??= $clinic->intro();
    $hours = $clinic->hours();
    $status = $hours->status(now()->toImmutable());

    $nav = array_filter([
        ['Tratamientos', route('site.treatments'), 'site.treatment*'],
        ['Equipo', route('site.team'), 'site.team'],
        $sections['promotions'] ? ['Promociones', route('site.promotions'), 'site.promotion*'] : null,
        ['Contacto', route('site.contact'), 'site.contact'],
    ]);
    $more = array_filter([
        $sections['cases'] ? ['Antes y después', route('site.cases'), 'site.cases'] : null,
        $sections['reviews'] ? ['Opiniones', route('site.reviews'), 'site.reviews'] : null,
        $sections['faq'] ? ['Preguntas frecuentes', route('site.faq'), 'site.faq'] : null,
    ]);
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
    @if ($image)
        <meta property="og:image" content="{{ url($image) }}">
    @endif
    <meta name="theme-color" content="#ffffff">
    <link rel="stylesheet" href="{{ asset('css/sitio.css') }}?v={{ @filemtime(public_path('css/sitio.css')) }}">
    @foreach ($jsonLd as $data)
        <script type="application/ld+json">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endforeach
</head>
<body>
<a href="#contenido" class="skip">Saltar al contenido</a>
<header class="site-header">
    <div class="wrap">
        <a href="{{ route('site.home') }}" class="wordmark">{{ $name }}</a>
        <nav class="site-nav" aria-label="Principal">
            @foreach ($nav as [$label, $url, $pattern])
                <a href="{{ $url }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <a href="{{ route('site.account') }}" @if (request()->routeIs('site.account*')) aria-current="page" @endif>Mi cuenta</a>
        </nav>
        @unless (request()->routeIs('site.booking'))
            <a href="{{ route('site.booking') }}" class="btn small">Reservar<span class="long">&nbsp;cita</span></a>
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
    </div>
</header>

<main id="contenido">
    <div @class(['wrap', 'wide' => $wide])>
        @if (session('status'))
            <div class="flash ok" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="flash bad" role="alert">{{ session('error') }}</div>
        @endif

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
<footer class="site-footer">
    <div class="wrap wide">
        <p class="name">{{ $name }}</p>
        <div @class(['footer-cols', 'without-hours' => ! $footerHours || $hours->isEmpty()])>
            <div>
                <h2>Dónde estamos</h2>
                @if ($clinic->fullAddress())
                    <p>{{ $clinic->fullAddress() }}</p>
                @endif
                @if ($clinic->directionsUrl())
                    <p><a href="{{ $clinic->directionsUrl() }}" rel="noopener" target="_blank">Cómo llegar</a></p>
                @endif
            </div>
            @if ($footerHours && ! $hours->isEmpty())
                <div>
                    <h2>Horario</h2>
                    @if ($status)
                        <p @class(['status', 'open' => $hours->isOpen(now()->toImmutable())])>{{ $status }}</p>
                    @endif
                    <dl class="hours">
                        @foreach ($hours->lines() as $line)
                            <dt>{{ $line['days'] }}</dt>
                            <dd>@foreach ($line['hours'] as $range)<span>{{ $range }}</span>@endforeach</dd>
                        @endforeach
                    </dl>
                    @if ($clinic->hoursNote())
                        <p class="note">{{ $clinic->hoursNote() }}</p>
                    @endif
                </div>
            @endif
            <div>
                <h2>Escríbenos</h2>
                <p>
                    @if ($clinic->whatsappUrl())
                        WhatsApp: <a href="{{ $clinic->whatsappUrl() }}" rel="noopener">{{ Phone::display($clinic->whatsapp()) }}</a><br>
                    @endif
                    @if ($clinic->phone())
                        Teléfono: <a href="{{ $clinic->phoneHref() }}">{{ $clinic->phone() }}</a><br>
                    @endif
                    @if ($clinic->email())
                        <a href="mailto:{{ $clinic->email() }}">{{ $clinic->email() }}</a>
                    @endif
                </p>
                @if ($clinic->socials())
                    <p class="socials">
                        @foreach ($clinic->socials() as $network => $url)
                            <a href="{{ $url }}" rel="noopener" target="_blank">{{ $network }}</a>
                        @endforeach
                    </p>
                @endif
            </div>
            <nav aria-label="Más páginas">
                <h2>El consultorio</h2>
                <ul>
                    @foreach ([...$nav, ...$more] as [$label, $url])
                        <li><a href="{{ $url }}">{{ $label }}</a></li>
                    @endforeach
                    <li><a href="{{ route('site.account') }}">Mi cuenta</a></li>
                    <li><a href="{{ route('site.privacy') }}">Política de privacidad</a></li>
                </ul>
            </nav>
        </div>
    </div>
</footer>
@endif

{{-- En el celular: barra fija abajo con lo que más se busca. En pantallas grandes queda el botón de WhatsApp. --}}
@if ($whatsappFloat)
    <nav class="action-bar" aria-label="Acciones rápidas">
        <a href="{{ route('site.booking') }}" class="btn">Reservar</a>
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
        <svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path d="M16.02 3C8.84 3 3 8.83 3 16c0 2.29.6 4.53 1.74 6.5L3 29l6.68-1.75A12.96 12.96 0 0 0 16.02 29C23.2 29 29 23.17 29 16S23.2 3 16.02 3Zm0 23.8c-2.02 0-4-.54-5.72-1.57l-.41-.24-3.96 1.04 1.06-3.86-.27-.4A10.73 10.73 0 0 1 5.2 16c0-5.96 4.85-10.8 10.82-10.8 5.96 0 10.8 4.84 10.8 10.8 0 5.96-4.84 10.8-10.8 10.8Zm5.93-8.09c-.33-.16-1.93-.95-2.23-1.06-.3-.11-.52-.16-.73.17-.22.32-.84 1.05-1.03 1.27-.19.22-.38.24-.71.08-.33-.16-1.38-.51-2.63-1.62-.97-.87-1.63-1.94-1.82-2.27-.19-.32-.02-.5.14-.66.15-.15.33-.38.49-.57.16-.19.22-.32.33-.54.11-.22.05-.4-.03-.57-.08-.16-.73-1.76-1-2.41-.26-.63-.53-.55-.73-.56h-.62c-.22 0-.57.08-.87.4-.3.33-1.14 1.11-1.14 2.71s1.17 3.15 1.33 3.36c.16.22 2.3 3.5 5.56 4.91.78.34 1.38.54 1.86.69.78.25 1.49.21 2.05.13.63-.09 1.93-.79 2.2-1.55.27-.76.27-1.41.19-1.55-.08-.14-.3-.22-.62-.38Z"/></svg>
    </a>
@endif
</body>
</html>
