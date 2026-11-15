@php
    use App\Services\Web\ClinicInfo;
    use App\Support\Markdown;

    $about = app(ClinicInfo::class)->about();
@endphp
<x-site-layout page="equipo" :wide="true" :json-ld="$jsonLd"
    description="Quién te atiende: especialidad, formación y número de colegiatura (COP).">
    {{-- La presentación del consultorio, bajo el encabezado de la página. --}}
    @if ($about)
        <div class="page-intro prose">{{ Markdown::paragraphs($about) }}</div>
    @endif

    @if ($doctors->isEmpty())
        <p class="panel">Pronto presentaremos a nuestro equipo.</p>
    @else
        @include('site.partials.doctors', ['doctors' => $doctors, 'full' => true])
    @endif
</x-site-layout>
