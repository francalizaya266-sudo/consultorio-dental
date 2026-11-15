@php
    use App\Services\Web\ClinicInfo;

    $reviewsUrl = app(ClinicInfo::class)->reviewsUrl();
@endphp
<x-site-layout page="opiniones" :wide="true" :json-ld="$jsonLd" description="Lo que dicen nuestros pacientes.">
    @if ($reviewsUrl)
        <p class="page-intro">Puedes leerlas todas, y dejar la tuya, en <a href="{{ $reviewsUrl }}" rel="noopener" target="_blank">nuestro perfil de Google</a>.</p>
    @endif

    @if ($reviews->isEmpty())
        <p class="panel">Pronto publicaremos opiniones de nuestros pacientes.</p>
    @else
        @include('site.partials.reviews', ['reviews' => $reviews])
    @endif
</x-site-layout>
