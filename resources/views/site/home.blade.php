@php
    use App\Services\Web\ClinicInfo;
    use App\Services\Web\PublicImages;
    use App\Support\CampaignText;
    use App\Support\Markdown;
    use App\Support\SiteArt;

    $clinic = app(ClinicInfo::class);
    $heroImage = $clinic->heroImageUrl();
    $googleRating = $clinic->googleRating();
    $status = $clinic->hours()->status(now()->toImmutable());
@endphp
{{-- La dirección, el horario y el WhatsApp ya están en "Dónde estamos": el pie no los repite. --}}
<x-site-layout :wide="true" :json-ld="$jsonLd" :image="$heroImage" :footer-contact="false">
    {{-- Quien vuelve desde su celular recordado ve primero lo suyo; quien no, la portada de siempre. --}}
    @if ($welcome)
        @include('site.partials.welcome', ['welcome' => $welcome])
    @endif

    {{--
        Lo primero: si está abierto, qué es el consultorio y el próximo horario libre, sacado de la agenda.
        Con la foto real del consultorio va la foto; sin ella, el diente 3D de la marca.
    --}}
    <section @class(['hero', 'with-photo' => $heroImage, 'with-art' => ! $heroImage, 'plain' => ! $featured]) aria-labelledby="titulo">
        @if ($heroImage)
            <figure class="hero-photo">
                <img src="{{ $heroImage }}" alt="{{ $clinic->name() }}" fetchpriority="high">
            </figure>
        @endif
        <div class="hero-text">
            @if ($status)
                <p @class(['eyebrow', 'open' => $clinic->hours()->isOpen(now()->toImmutable())])>{{ $status }}</p>
            @endif
            <h1 id="titulo">{{ $clinic->headline() }}</h1>
            @if ($clinic->intro())
                <p class="lead">{{ $clinic->intro() }}</p>
            @endif
            @if ($googleRating)
                @include('site.partials.google-rating', ['rating' => $googleRating])
            @endif
            <div class="row">
                <a href="{{ route('site.booking') }}" class="btn">Reservar cita</a>
                @if ($clinic->whatsappUrl())
                    <a href="{{ $clinic->whatsappUrl() }}" class="btn quiet" rel="noopener">Escribir por WhatsApp</a>
                @endif
            </div>
        </div>

        {{-- Lo que tranquiliza antes de reservar, en tres frases. --}}
        @if ($featured)
            <ul class="trust">
                <li>Horarios libres de verdad</li>
                <li>Confirmas por WhatsApp</li>
                <li>Pagas en el consultorio</li>
            </ul>
        @endif

        @unless ($heroImage)
            <figure class="hero-art">
                <img src="{{ SiteArt::url('portada') }}" alt="" width="900" height="820" fetchpriority="high">
            </figure>
        @endunless

        @if ($featured && $nextFree)
            @include('site.partials.next-free', ['treatment' => $featured, 'nextFree' => $nextFree])
        @endif
    </section>

    @foreach ($campaigns as $campaign)
        <a href="{{ route('site.promotion', $campaign->slug) }}" @class(['promo-band', 'with-photo' => $campaign->image_path])>
            @if ($campaign->image_path)
                <img src="{{ PublicImages::url($campaign->image_path) }}" alt="" loading="lazy" class="promo-photo">
            @endif
            <span class="promo-text">
                <span class="tag">Promoción</span>
                <strong>{{ $campaign->name }}</strong>
                @if ($campaign->summary)
                    <span>{{ $campaign->summary }}</span>
                @endif
                <span class="until">Hasta el {{ $campaign->ends_at->translatedFormat('j \d\e F') }}</span>
                <span class="go">Ver la promoción</span>
            </span>
            {{-- Sin foto propia, la promoción lleva el diente sonriente. --}}
            @unless ($campaign->image_path)
                <img src="{{ SiteArt::url('sonrisa') }}" alt="" width="450" height="410" loading="lazy" class="promo-art">
            @endunless
        </a>
    @endforeach

    {{-- Cómo se reserva, antes de pedir ningún dato. Solo si la web tiene algo que se pueda reservar. --}}
    @if ($featured)
        <section class="section" aria-labelledby="como">
            <div class="section-head">
                <h2 id="como">Reserva en tres pasos</h2>
                <p>Sin llamadas ni esperas: lo haces desde tu celular.</p>
            </div>
            <ol class="how">
                <li>
                    <img src="{{ SiteArt::url('calendario') }}" alt="" width="410" height="380" loading="lazy">
                    <strong>Elige día y hora</strong>
                    <span>Ves los horarios que están libres y tocas el que te queda bien.</span>
                </li>
                <li>
                    <img src="{{ SiteArt::url('escudo') }}" alt="" width="380" height="380" loading="lazy">
                    <strong>Confirma por WhatsApp</strong>
                    <span>Te escribimos con un enlace para confirmar, cambiar el horario o cancelar.</span>
                </li>
                <li>
                    <img src="{{ SiteArt::url('sonrisa') }}" alt="" width="450" height="410" loading="lazy">
                    <strong>Ven a tu cita</strong>
                    <span>{{ CampaignText::HOW_TO_PAY }}</span>
                </li>
            </ol>
        </section>
    @endif

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

    {{-- La especialidad que declara el doctor, con su tratamiento: lo que distingue al consultorio. --}}
    @if ($specialty)
        <section class="section feature" aria-labelledby="especialidad">
            <div>
                <p class="kicker">Nuestra especialidad</p>
                <h2 id="especialidad">{{ $specialty->name }}</h2>
                @if ($specialty->summary)
                    <p>{{ $specialty->summary }}</p>
                @endif
                <a href="{{ route('site.treatment', $specialty->slug) }}" class="btn">Conocer el tratamiento</a>
            </div>
            <img src="{{ SiteArt::url(SiteArt::keyFor($specialty)) }}" alt="" width="640" height="640" loading="lazy">
        </section>
    @endif

    {{-- Quién atiende y la presentación del consultorio van juntos: son la misma respuesta. --}}
    @if ($doctors->isNotEmpty())
        <section class="section band" aria-labelledby="equipo">
            <div class="section-head">
                <h2 id="equipo">{{ $doctors->count() === 1 ? 'Quién te atiende' : 'Quiénes te atienden' }}</h2>
                @if ($clinic->about())
                    <div class="prose">{{ Markdown::paragraphs($clinic->about()) }}</div>
                @elseif ($doctors->count() === 1)
                    <p>Con su número de colegiatura (COP) a la vista.</p>
                @else
                    <p>Cirujanos dentistas colegiados. Su número de colegiatura (COP) está a la vista.</p>
                @endif
            </div>
            @include('site.partials.doctors', ['doctors' => $doctors, 'full' => false])
        </section>
    @elseif ($clinic->about())
        <section class="section about" aria-labelledby="nosotros">
            <h2 id="nosotros">Sobre {{ $clinic->name() }}</h2>
            <div class="prose">{{ Markdown::paragraphs($clinic->about()) }}</div>
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

    @if ($faqs->isNotEmpty())
        <section class="section split" aria-labelledby="preguntas">
            <div class="section-head">
                <h2 id="preguntas">Preguntas frecuentes</h2>
            </div>
            @include('site.partials.faqs', ['faqs' => $faqs])
            <p><a href="{{ route('site.faq') }}" class="arrow">Ver todas las preguntas</a></p>
        </section>
    @endif

    @include('site.partials.contact')
</x-site-layout>
