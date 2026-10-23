{{-- whatsappFloat: false en pantallas donde el botón flotante taparía los horarios --}}
@props(['title' => null, 'description' => null, 'noindex' => false, 'whatsappFloat' => true])
@php
    use App\Models\Setting;
    use App\Support\Phone;

    $clinic = (string) Setting::get('clinic.name');
    $whatsapp = (string) Setting::get('clinic.whatsapp');
    $whatsappUrl = Phone::isValid($whatsapp) ? 'https://wa.me/'.Phone::forWhatsApp($whatsapp).'?text='.rawurlencode('Hola, quisiera información.') : null;
    $phone = Setting::get('clinic.phone');
    $address = Setting::get('clinic.address');
@endphp
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · '.$clinic : $clinic }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($noindex)
        <meta name="robots" content="noindex">
    @endif
    <meta name="theme-color" content="#ffffff">
    <link rel="stylesheet" href="{{ asset('css/sitio.css') }}?v={{ @filemtime(public_path('css/sitio.css')) }}">
</head>
<body>
<header class="site-header">
    <div class="wrap">
        <a href="{{ url('/') }}" class="wordmark">{{ $clinic }}</a>
        <nav class="site-nav" aria-label="Principal">
            <a href="{{ route('site.account') }}" @if (request()->routeIs('site.account*')) aria-current="page" @endif>Mi cuenta</a>
        </nav>
        @unless (request()->routeIs('site.booking'))
            <a href="{{ route('site.booking') }}" class="btn small">Reservar<span class="long">&nbsp;cita</span></a>
        @endunless
    </div>
</header>

<main>
    <div class="wrap">
        @if (session('status'))
            <div class="flash ok" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="flash bad" role="alert">{{ session('error') }}</div>
        @endif

        {{ $slot }}
    </div>
</main>

<footer class="site-footer">
    <div class="wrap">
        <p class="name">{{ $clinic }}</p>
        <div class="footer-cols">
            @if ($address)
                <p>{{ $address }}</p>
            @endif
            <p>
                @if ($phone)
                    Teléfono: <a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a><br>
                @endif
                @if ($whatsappUrl)
                    WhatsApp: <a href="{{ $whatsappUrl }}" rel="noopener">{{ Phone::display($whatsapp) }}</a>
                @endif
            </p>
            <p><a href="{{ route('site.privacy') }}">Política de privacidad</a></p>
        </div>
    </div>
</footer>

@if ($whatsappUrl && $whatsappFloat)
    <a href="{{ $whatsappUrl }}" class="wa-float" rel="noopener" aria-label="Escríbenos por WhatsApp">
        <svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path d="M16.02 3C8.84 3 3 8.83 3 16c0 2.29.6 4.53 1.74 6.5L3 29l6.68-1.75A12.96 12.96 0 0 0 16.02 29C23.2 29 29 23.17 29 16S23.2 3 16.02 3Zm0 23.8c-2.02 0-4-.54-5.72-1.57l-.41-.24-3.96 1.04 1.06-3.86-.27-.4A10.73 10.73 0 0 1 5.2 16c0-5.96 4.85-10.8 10.82-10.8 5.96 0 10.8 4.84 10.8 10.8 0 5.96-4.84 10.8-10.8 10.8Zm5.93-8.09c-.33-.16-1.93-.95-2.23-1.06-.3-.11-.52-.16-.73.17-.22.32-.84 1.05-1.03 1.27-.19.22-.38.24-.71.08-.33-.16-1.38-.51-2.63-1.62-.97-.87-1.63-1.94-1.82-2.27-.19-.32-.02-.5.14-.66.15-.15.33-.38.49-.57.16-.19.22-.32.33-.54.11-.22.05-.4-.03-.57-.08-.16-.73-1.76-1-2.41-.26-.63-.53-.55-.73-.56h-.62c-.22 0-.57.08-.87.4-.3.33-1.14 1.11-1.14 2.71s1.17 3.15 1.33 3.36c.16.22 2.3 3.5 5.56 4.91.78.34 1.38.54 1.86.69.78.25 1.49.21 2.05.13.63-.09 1.93-.79 2.2-1.55.27-.76.27-1.41.19-1.55-.08-.14-.3-.22-.62-.38Z"/></svg>
    </a>
@endif
</body>
</html>
