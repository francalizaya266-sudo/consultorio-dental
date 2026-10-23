<x-site-layout title="Nuestro equipo" :wide="true" :json-ld="$jsonLd"
    description="Quién te atiende: especialidad, formación y número de colegiatura (COP).">
    <section class="page-head">
        <h1>Nuestro equipo</h1>
        @if ($doctors->count() === 1)
            <p class="lead">Te atiende {{ $doctors->first()->name }}, con colegiatura en el Colegio Odontológico del Perú.</p>
        @else
            <p class="lead">Cirujanos dentistas colegiados en el Colegio Odontológico del Perú. Puedes elegir con quién atenderte al reservar.</p>
        @endif
    </section>

    @if ($doctors->isEmpty())
        <p class="panel">Pronto presentaremos a nuestro equipo.</p>
    @else
        @include('site.partials.doctors', ['doctors' => $doctors, 'full' => true])
    @endif
</x-site-layout>
