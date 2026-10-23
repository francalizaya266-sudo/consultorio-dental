<x-site-layout title="Nuestro equipo" :wide="true" :json-ld="$jsonLd"
    description="Conoce a los cirujanos dentistas que te atienden: especialidad, formación y número de colegiatura (COP).">
    <section class="page-head">
        <h1>Nuestro equipo</h1>
        <p class="lead">Cirujanos dentistas colegiados en el Colegio Odontológico del Perú. Puedes elegir con quién atenderte al reservar.</p>
    </section>

    @if ($doctors->isEmpty())
        <p class="panel">Pronto presentaremos a nuestro equipo.</p>
    @else
        @include('site.partials.doctors', ['doctors' => $doctors, 'full' => true])
    @endif
</x-site-layout>
