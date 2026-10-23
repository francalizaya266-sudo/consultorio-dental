@php
    use App\Services\Web\ClinicInfo;

    $clinic = app(ClinicInfo::class);
@endphp
<x-site-layout title="Contacto y cómo llegar" :wide="true" :json-ld="$jsonLd"
    :description="'Dirección, horario de atención, WhatsApp y mapa'.($clinic->fullAddress() ? ': '.$clinic->fullAddress() : '').'.'">
    @include('site.partials.contact', ['headingLevel' => 'h1'])

    <section class="section cta-band">
        <h2>¿Quieres una cita?</h2>
        <p>Elige el día y la hora en línea; te confirmamos por WhatsApp.</p>
        <a href="{{ route('site.booking') }}" class="btn">Reservar cita</a>
    </section>
</x-site-layout>
