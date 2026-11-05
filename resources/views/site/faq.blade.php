@php
    $whatsappUrl = app(\App\Services\Web\ClinicInfo::class)->whatsappUrl('Hola, tengo una pregunta.');
@endphp
<x-site-layout title="Preguntas frecuentes" :wide="true" :json-ld="$jsonLd" description="Respuestas sobre citas, pagos, tratamientos y cómo llegar.">
    <section class="page-head">
        <h1>Preguntas frecuentes</h1>
        <p class="lead">¿No encuentras tu duda? @if ($whatsappUrl)<a href="{{ $whatsappUrl }}" rel="noopener">Escríbenos por WhatsApp</a>@else Escríbenos por WhatsApp @endif y te respondemos.</p>
    </section>

    @if ($general->isEmpty() && $byTreatment->isEmpty())
        <p class="panel">Pronto publicaremos las preguntas más comunes.</p>
    @endif

    {{-- En pantallas grandes, cada grupo con su título a la izquierda y las preguntas a la derecha. --}}
    @if ($general->isNotEmpty())
        <section class="section faq-group split" aria-labelledby="faq-generales">
            <div class="section-head">
                <h2 id="faq-generales">Generales</h2>
            </div>
            @include('site.partials.faqs', ['faqs' => $general])
        </section>
    @endif

    @foreach ($byTreatment as $treatmentName => $faqs)
        <section class="section faq-group split" aria-label="{{ $treatmentName }}">
            <div class="section-head">
                <h2><a href="{{ route('site.treatment', $faqs->first()->treatment->slug) }}">{{ $treatmentName }}</a></h2>
            </div>
            @include('site.partials.faqs', ['faqs' => $faqs])
        </section>
    @endforeach
</x-site-layout>
