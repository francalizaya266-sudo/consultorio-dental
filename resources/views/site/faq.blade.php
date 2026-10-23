<x-site-layout title="Preguntas frecuentes" :wide="true" :json-ld="$jsonLd" description="Respuestas sobre citas, pagos, tratamientos y cómo llegar.">
    <section class="page-head">
        <h1>Preguntas frecuentes</h1>
        <p class="lead">¿No encuentras tu duda? Escríbenos por WhatsApp y te respondemos.</p>
    </section>

    @if ($general->isEmpty() && $byTreatment->isEmpty())
        <p class="panel">Pronto publicaremos las preguntas más comunes.</p>
    @endif

    @if ($general->isNotEmpty())
        <section class="section faq-group">
            @include('site.partials.faqs', ['faqs' => $general])
        </section>
    @endif

    @foreach ($byTreatment as $treatmentName => $faqs)
        <section class="section faq-group" aria-label="{{ $treatmentName }}">
            <h2><a href="{{ route('site.treatment', $faqs->first()->treatment->slug) }}">{{ $treatmentName }}</a></h2>
            @include('site.partials.faqs', ['faqs' => $faqs])
        </section>
    @endforeach
</x-site-layout>
