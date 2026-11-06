{{-- Error sin base de datos ni sesión: sale aunque el sistema esté caído. --}}
@php
    $clinic = config('consultorio.defaults.clinic.name');
    $whatsapp = preg_replace('/\D/', '', (string) config('consultorio.defaults.clinic.whatsapp'));
@endphp
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ $clinic }}</title>
    <link rel="stylesheet" href="{{ asset('css/sitio.css') }}">
</head>
<body>
    <main class="error-page">
        <div class="error-card stack">
            <p class="error-brand">{{ $clinic }}</p>
            <h1>{{ $title }}</h1>
            <p class="lead">{{ $lead }}</p>
            <p class="error-actions">
                @if ($inPanel)
                    <a href="{{ url('/panel') }}" class="btn">Volver al panel</a>
                    <button type="button" class="btn quiet" onclick="history.back()">Atrás</button>
                @else
                    <button type="button" class="btn" onclick="location.reload()">Volver a intentar</button>
                    @if ($whatsapp !== '')
                        <a href="https://wa.me/{{ $whatsapp }}" class="btn whatsapp" rel="noopener">WhatsApp</a>
                    @endif
                @endif
            </p>
        </div>
    </main>
</body>
</html>
