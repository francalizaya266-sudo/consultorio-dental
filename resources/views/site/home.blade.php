@php
    use App\Services\Web\ClinicInfo;
    use App\Services\Web\PublicImages;
    use App\Support\Markdown;

    $clinic = app(ClinicInfo::class);
@endphp
<x-site-layout :wide="true" :json-ld="$jsonLd">
    {{-- Lo primero: qué es, dónde y el próximo horario libre de verdad, sacado de la agenda. --}}
    <section class="hero" aria-labelledby="titulo">
        <div class="hero-text">
            <h1 id="titulo">{{ $clinic->headline() }}</h1>
            @if ($clinic->intro())
                <p class="lead">{{ $clinic->intro() }}</p>
            @endif
            <div class="row">
                <a href="{{ route('site.booking') }}" class="btn">Reservar cita</a>
                @if ($clinic->whatsappUrl())
                    <a href="{{ $clinic->whatsappUrl() }}" class="btn quiet" rel="noopener">Escribir por WhatsApp</a>
                @endif
            </div>
            @if ($clinic->fullAddress())
                <p class="where">{{ $clinic->fullAddress() }}@if ($status = $clinic->hours()->status(now()->toImmutable())) · <span @class(['open' => $clinic->hours()->isOpen(now()->toImmutable())])>{{ $status }}</span>@endif</p>
            @endif
        </div>

        @if ($featured && $nextFree)
            @include('site.partials.next-free', ['treatment' => $featured, 'nextFree' => $nextFree])
        @endif
    </section>

    @foreach ($campaigns as $campaign)
        <a href="{{ route('site.promotion', $campaign->slug) }}" class="promo-band">
            @if ($campaign->image_path)
                <img src="{{ PublicImages::url($campaign->image_path) }}" alt="" loading="lazy">
            @endif
            <span class="promo-text">
                <strong>{{ $campaign->name }}</strong>
                @if ($campaign->summary)
                    <span>{{ $campaign->summary }}</span>
                @endif
                <span class="until">Hasta el {{ $campaign->ends_at->translatedFormat('j \d\e F') }}</span>
            </span>
        </a>
    @endforeach

    {{-- Tratamientos como un tarifario: duración y precio desde, sin letra chica. --}}
    @if ($treatments->isNotEmpty())
        <section class="section" aria-labelledby="tratamientos">
            <div class="section-head">
                <h2 id="tratamientos">Tratamientos y precios</h2>
                <p>Precios referenciales “desde”. El costo exacto te lo da el doctor en tu evaluación, antes de empezar.</p>
            </div>
            @include('site.partials.price-list', ['treatments' => $treatments->take(6)])
            @if ($treatments->count() > 6)
                <p><a href="{{ route('site.treatments') }}" class="arrow">Ver los {{ $treatments->count() }} tratamientos</a></p>
            @endif
        </section>
    @endif

    @if ($doctors->isNotEmpty())
        <section class="section" aria-labelledby="equipo">
            <div class="section-head">
                @if ($doctors->count() === 1)
                    <h2 id="equipo">Quién te atiende</h2>
                    <p>Con su número de colegiatura (COP) a la vista.</p>
                @else
                    <h2 id="equipo">Quiénes te atienden</h2>
                    <p>Cirujanos dentistas colegiados. Su número de colegiatura (COP) está a la vista.</p>
                @endif
            </div>
            @include('site.partials.doctors', ['doctors' => $doctors, 'full' => false])
        </section>
    @endif

    @if ($cases->isNotEmpty())
        <section class="section" aria-labelledby="casos">
            <div class="section-head">
                <h2 id="casos">Antes y después</h2>
                <p>Pacientes que nos autorizaron a mostrar su resultado.</p>
            </div>
            @include('site.partials.cases', ['cases' => $cases])
            <p><a href="{{ route('site.cases') }}" class="arrow">Ver más casos</a></p>
        </section>
    @endif

    @if ($reviews->isNotEmpty())
        <section class="section" aria-labelledby="opiniones">
            <div class="section-head">
                <h2 id="opiniones">Lo que dicen nuestros pacientes</h2>
            </div>
            @include('site.partials.reviews', ['reviews' => $reviews])
            <p class="row">
                <a href="{{ route('site.reviews') }}" class="arrow">Leer más opiniones</a>
                @if ($clinic->reviewsUrl())
                    <a href="{{ $clinic->reviewsUrl() }}" class="arrow" rel="noopener" target="_blank">Vernos en Google</a>
                @endif
            </p>
        </section>
    @endif

    @if ($clinic->about())
        <section class="section about" aria-labelledby="nosotros">
            <h2 id="nosotros">Sobre {{ $clinic->name() }}</h2>
            <div class="prose">{{ Markdown::paragraphs($clinic->about()) }}</div>
        </section>
    @endif

    @if ($faqs->isNotEmpty())
        <section class="section" aria-labelledby="preguntas">
            <div class="section-head">
                <h2 id="preguntas">Preguntas frecuentes</h2>
            </div>
            @include('site.partials.faqs', ['faqs' => $faqs])
            <p><a href="{{ route('site.faq') }}" class="arrow">Ver todas las preguntas</a></p>
        </section>
    @endif

    @include('site.partials.contact')
</x-site-layout>
