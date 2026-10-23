@php
    use App\Services\Web\ClinicInfo;

    $district = app(ClinicInfo::class)->district();
@endphp
<x-site-layout title="Tratamientos y precios" :wide="true" :json-ld="$jsonLd"
    :description="'Tratamientos dentales'.($district ? ' en '.$district : '').' con duración y precio referencial. Reserva tu evaluación en línea.'">
    <section class="page-head">
        <h1>Tratamientos y precios</h1>
        <p class="lead">Cuánto dura cada uno y desde cuánto cuesta. El precio exacto te lo da el doctor en tu evaluación, antes de empezar, y se paga en el consultorio.</p>
    </section>

    @if ($treatments->isEmpty())
        <p class="panel">Pronto publicaremos nuestros tratamientos. Mientras tanto, escríbenos por WhatsApp.</p>
    @else
        @include('site.partials.price-list', ['treatments' => $treatments])
    @endif

    <section class="section cta-band">
        <h2>¿No sabes qué necesitas?</h2>
        <p>Empieza con una evaluación: el doctor revisa, te explica las opciones y te da el presupuesto.</p>
        <a href="{{ route('site.booking') }}" class="btn">Reservar evaluación</a>
    </section>
</x-site-layout>
