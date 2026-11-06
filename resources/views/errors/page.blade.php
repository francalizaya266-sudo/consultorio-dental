{{--
    Página de error en español. En la web pública va con el encabezado y el pie de siempre, para que
    el paciente que tocó un enlace viejo pueda seguir. En el panel, y en 500/503 (cuando la base de datos
    puede estar caída), va la versión sin base de datos.
    Recibe: title, lead, y opcionales panelTitle, panelLead y plain (forzar la versión sin base de datos).
--}}
@php
    $inPanel = request()->is('panel', 'panel/*');
    $title = $inPanel ? ($panelTitle ?? $title) : $title;
    $lead = $inPanel ? ($panelLead ?? $lead) : $lead;
@endphp
@if ($inPanel || ($plain ?? false))
    @include('errors.plain', ['title' => $title, 'lead' => $lead, 'inPanel' => $inPanel])
@else
    <x-site-layout :title="$title" :noindex="true">
        <section class="stack error-site">
            <h1>{{ $title }}</h1>
            <p class="lead">{{ $lead }}</p>
            <p class="error-actions">
                <a href="{{ route('site.home') }}" class="btn">Ir al inicio</a>
                <a href="{{ route('site.booking') }}" class="btn quiet">Reservar cita</a>
            </p>
        </section>
    </x-site-layout>
@endif
