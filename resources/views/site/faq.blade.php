{{-- Quien no encuentra su duda tiene el WhatsApp en la franja que cierra la página. --}}
<x-site-layout page="preguntas" :wide="true" :json-ld="$jsonLd" description="Respuestas sobre citas, pagos, tratamientos y cómo llegar.">
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
