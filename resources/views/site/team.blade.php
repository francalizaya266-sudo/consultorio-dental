@php
    use App\Services\Web\ClinicInfo;
    use App\Services\Web\PageTexts;
    use App\Support\Markdown;
    use App\Support\SiteIcon;

    $about = app(ClinicInfo::class)->about();
    $values = app(PageTexts::class)->teamValues();
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

        {{-- Lo que distingue al equipo, en pocas palabras (Página web › Textos de las páginas). --}}
        @if ($values)
            <ul class="values" aria-label="Lo que nos distingue">
                @foreach ($values as $value)
                    <li>{{ SiteIcon::svg($value['icon']) }}<strong>{{ $value['title'] }}</strong>@if ($value['text'])<span>{{ $value['text'] }}</span>@endif</li>
                @endforeach
            </ul>
        @endif
    @endif
</x-site-layout>
