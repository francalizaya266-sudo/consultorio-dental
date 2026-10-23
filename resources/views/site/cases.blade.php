<x-site-layout title="Antes y después" :wide="true" :json-ld="$jsonLd" description="Resultados reales de pacientes que autorizaron mostrar sus fotos.">
    <section class="page-head">
        <h1>Antes y después</h1>
        <p class="lead">Resultados de pacientes que nos autorizaron a mostrar sus fotos. Cada caso es distinto: en tu evaluación el doctor te dice qué puedes esperar.</p>
    </section>

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
