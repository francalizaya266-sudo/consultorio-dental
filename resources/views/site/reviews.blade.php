@php
    use App\Services\Web\ClinicInfo;

    $reviewsUrl = app(ClinicInfo::class)->reviewsUrl();
@endphp
<x-site-layout title="Opiniones de pacientes" :wide="true" :json-ld="$jsonLd" description="Lo que dicen nuestros pacientes.">
    <section class="page-head">
        <h1>Opiniones de pacientes</h1>
        <p class="lead">Copiadas tal como las escribieron nuestros pacientes.@if ($reviewsUrl) Puedes leerlas todas, y dejar la tuya, en <a href="{{ $reviewsUrl }}" rel="noopener" target="_blank">nuestro perfil de Google</a>.@endif</p>
    </section>

    @if ($reviews->isEmpty())
        <p class="panel">Pronto publicaremos opiniones de nuestros pacientes.</p>
    @else
        @include('site.partials.reviews', ['reviews' => $reviews])
    @endif
</x-site-layout>
