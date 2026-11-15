<x-site-layout page="casos" :wide="true" :json-ld="$jsonLd" description="Resultados reales de pacientes que autorizaron mostrar sus fotos.">
    @if ($treatments->count() > 1)
        <nav class="chips" aria-label="Filtrar por tratamiento">
            <a href="{{ route('site.cases') }}" class="chip" @if (! $selected) aria-current="page" @endif>Todos</a>
            @foreach ($treatments as $treatment)
                <a href="{{ route('site.cases', ['tratamiento' => $treatment->slug]) }}" class="chip" @if ($selected?->is($treatment)) aria-current="page" @endif>{{ $treatment->name }}</a>
            @endforeach
        </nav>
    @endif

    @if ($cases->isEmpty())
        <p class="panel">Pronto publicaremos casos de nuestros pacientes.</p>
    @else
        @include('site.partials.cases', ['cases' => $cases, 'withDescription' => true])
    @endif
</x-site-layout>
