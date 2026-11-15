@php
    use App\Services\Web\ClinicInfo;
    use App\Services\Web\PublicImages;
    use App\Support\CampaignText;
    use App\Support\Initials;
    use App\Support\Markdown;
    use App\Support\SiteArt;
    use App\Support\SiteIcon;
    use Illuminate\Support\Str;

    $clinic = app(ClinicInfo::class);
    $heroImage = $clinic->heroImageUrl();
    $googleRating = $clinic->googleRating();
    $status = $clinic->hours()->status(now()->toImmutable());
    // El título en dos tonos, como la marca: la segunda mitad va en celeste y en cursiva. Si la primera mitad
    // trae una coma, lo que le sigue va en letra delgada: "Tu sonrisa," fuerte y "nuestra" delgada.
    $words = preg_split('/\s+/', $clinic->headline(), flags: PREG_SPLIT_NO_EMPTY);
    $firstHalf = (int) ceil(count($words) / 2);
    $titleStart = implode(' ', array_slice($words, 0, $firstHalf));
    $comma = mb_strpos($titleStart, ',');
    $titleStrong = $comma === false ? $titleStart : mb_substr($titleStart, 0, $comma + 1);
    $titleThin = $comma === false ? '' : trim(mb_substr($titleStart, $comma + 1));
    $titleAccent = implode(' ', array_slice($words, $firstHalf));
    // Lo que tranquiliza antes de reservar. Sin reserva en línea no se promete nada de eso.
    $trust = $featured ? array_filter([
        ['calendar-check', 'Horarios libres de verdad'],
        ['chat-check', 'Confirmas por WhatsApp'],
        ['wallet', 'Pagas en el consultorio'],
        $treatments->contains(fn ($treatment) => $treatment->webPrice() !== null) ? ['tag', 'Precios a la vista'] : null,
    ]) : [];
    // Junto al acceso rápido entran dos filas de tratamientos; el resto está en su página.
    $shown = $treatments->take(8);
@endphp
{{-- La dirección, el horario y el WhatsApp ya están en "Dónde estamos": el pie no los repite. --}}
<x-site-layout :wide="true" :json-ld="$jsonLd" :image="$heroImage" :footer-contact="false">
    {{--
        La portada, en azul noche a todo el ancho: si está abierto, la frase del consultorio, qué es y el implante
        3D de la marca. Con la foto real del consultorio, la foto va a la derecha. Quien vuelve desde su celular recordado
        ve primero lo suyo, dentro de la misma franja.
    --}}
    <x-slot:hero>
        <div @class(['hero-band', 'with-photo' => $heroImage])>
            @if ($heroImage)
                <figure class="hero-photo">
                    <img src="{{ $heroImage }}" alt="{{ $clinic->name() }}" fetchpriority="high">
                </figure>
            @endif
            <div class="wrap wide">
                @if ($welcome)
                    @include('site.partials.welcome', ['welcome' => $welcome])
                @endif

                <section @class(['hero', 'with-photo' => $heroImage, 'with-art' => ! $heroImage, 'plain' => ! $featured]) aria-labelledby="titulo">
                    <div class="hero-text">
                        @if ($status)
                            <p @class(['open-now', 'open' => $clinic->hours()->isOpen(now()->toImmutable())])>{{ $status }}</p>
                        @endif
                        @if ($clinic->tagline())
                            <p class="eyebrow">{{ $clinic->tagline() }}</p>
                        @endif
                        <h1 id="titulo">{{ $titleStrong }}@if ($titleThin !== '') <span>{{ $titleThin }}</span>@endif@if ($titleAccent !== '') <em>{{ $titleAccent }}</em>@endif</h1>
                        @if ($clinic->intro())
                            <p class="lead">{{ $clinic->intro() }}</p>
                        @endif
                        @if ($googleRating)
                            @include('site.partials.google-rating', ['rating' => $googleRating])
                        @endif
                        <div class="row">
                            <a href="{{ route('site.booking') }}" class="btn light">{{ SiteIcon::svg('calendar') }}Reservar cita</a>
                            @if ($treatments->isNotEmpty())
                                <a href="#servicios" class="btn ghost">Conocer más{{ SiteIcon::svg('arrow-down') }}</a>
                            @endif
                        </div>
                    </div>

                    {{--
                        El implante con su cinta de luz: es la imagen de la marca, con foto o sin ella. Va en dos capas,
                        el implante de bordes limpios y encima la cinta, que es la que lleva el resplandor (sitio.css).
                        Con foto, en el celular no se ve: se pide solo cuando hace falta, y lo primero en cargar es la foto.
                    --}}
                    <figure class="hero-art">
                        {{ SiteArt::coverLayer('implante-portada', lazy: (bool) $heroImage) }}
                        {{ SiteArt::coverLayer('implante-portada-luz', lazy: (bool) $heroImage) }}
                    </figure>
                </section>
            </div>
        </div>

        @if ($trust)
            <div class="wrap wide">
                <ul class="trust">
                    @foreach ($trust as [$icon, $phrase])
                        <li>{{ SiteIcon::svg($icon) }}<span>{{ $phrase }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-slot:hero>

    {{--
        Lo que más se busca, a un toque, y al lado los servicios con su precio "desde". En el celular, "Reservar
        cita" y WhatsApp ya están siempre en la barra de abajo: aquí no se repiten (sitio.css).
    --}}
    <div class="start">
        <aside class="quick" aria-labelledby="acceso">
            <h2 id="acceso">{{ SiteIcon::svg('calendar') }}Acceso rápido</h2>
            <p>¿Qué necesitas hacer?</p>
            @if ($featured && $nextFree)
                @include('site.partials.next-free', ['treatment' => $featured, 'nextFree' => $nextFree, 'headingLevel' => 'h3'])
            @endif
            <div class="quick-actions">
                <a href="{{ route('site.booking') }}" class="btn light in-bar">{{ SiteIcon::svg('calendar') }}Reservar cita{{ SiteIcon::svg('arrow-right') }}</a>
                @if ($clinic->whatsappUrl())
                    <a href="{{ $clinic->whatsappUrl() }}" class="btn ghost in-bar" rel="noopener">{{ SiteIcon::svg('whatsapp') }}Escribir por WhatsApp</a>
                @endif
                @if ($clinic->directionsUrl())
                    <a href="{{ $clinic->directionsUrl() }}" class="btn ghost" rel="noopener" target="_blank">{{ SiteIcon::svg('pin') }}Cómo llegar</a>
                @endif
            </div>
        </aside>

        @if ($treatments->isNotEmpty())
            <section class="services" aria-labelledby="servicios">
                <div class="section-head">
                    <h2 id="servicios">Nuestros servicios</h2>
                    <p>Precios referenciales “desde”. El costo exacto te lo da el doctor en tu evaluación, antes de empezar.</p>
                    @if ($treatments->count() > $shown->count())
                        <a href="{{ route('site.treatments') }}" class="arrow">Ver los {{ $treatments->count() }} servicios</a>
                    @endif
                </div>
                @include('site.partials.service-cards', ['treatments' => $shown])
            </section>
        @endif
    </div>

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

    {{-- La especialidad que declara el doctor, con su tratamiento: lo que distingue al consultorio, junto a los servicios. --}}
    @if ($specialty)
        <section class="section feature" aria-labelledby="especialidad">
            <div>
                <p class="kicker">Nuestra especialidad</p>
                <h2 id="especialidad">{{ $specialty->name }}</h2>
                @if ($specialty->summary)
                    <p>{{ $specialty->summary }}</p>
                @endif
                <a href="{{ route('site.treatment', $specialty->slug) }}" class="btn">Conocer el tratamiento{{ SiteIcon::svg('arrow-right') }}</a>
            </div>
            {{ SiteArt::feature($specialty) }}
        </section>
    @endif

    {{-- Cómo se reserva, antes de pedir ningún dato. Solo si la web tiene algo que se pueda reservar. --}}
    @if ($featured)
        <section class="section how-band" aria-labelledby="como">
            <div class="section-head">
                <h2 id="como">Reserva en tres pasos</h2>
                <p>Sin llamadas ni esperas: lo haces desde tu celular.</p>
                <a href="{{ route('site.booking') }}" class="btn">Reservar cita{{ SiteIcon::svg('arrow-right') }}</a>
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
            <img src="{{ SiteArt::url('portada') }}" alt="" width="900" height="820" loading="lazy" class="how-art">
        </section>
    @endif

    {{--
        Quién atiende, en breve: quien encabeza el equipo, con su especialidad y su colegiatura. Su presentación
        completa, la del consultorio y el resto del equipo están en la página Equipo: aquí no se repiten.
    --}}
    @if ($lead = $doctors->first())
        @php($others = $doctors->count() - 1)
        <section class="section meet" aria-labelledby="equipo">
            @if ($lead->photo_path)
                <img src="{{ PublicImages::url($lead->photo_path) }}" alt="{{ $lead->name }}" class="portrait" loading="lazy" width="320" height="320">
            @else
                {{-- Sin foto: sus iniciales, nunca una foto de banco. --}}
                <span class="portrait monogram" aria-hidden="true">{{ Initials::of($lead->name) }}</span>
            @endif
            <div class="who">
                <p class="kicker">{{ $others ? 'Quiénes te atienden' : 'Quién te atiende' }}</p>
                <h2 id="equipo">{{ $lead->name }}</h2>
                @if ($lead->specialty)
                    <p class="specialty">{{ $lead->specialty }}</p>
                @endif
                @if ($lead->cop_number || $lead->rne_number)
                    <p class="credentials">{{ implode(' · ', array_filter([$lead->cop_number ? 'COP '.$lead->cop_number : null, $lead->rne_number ? 'RNE '.$lead->rne_number : null])) }}</p>
                @endif
            </div>
            @if ($lead->bio || $others)
                <div class="more">
                    @if ($lead->bio)
                        <p>{{ Str::limit($lead->bio, 160) }}</p>
                    @endif
                    @if ($others)
                        <p>{{ $others === 1 ? 'Junto con otro profesional del equipo.' : "Junto con otros {$others} profesionales del equipo." }}</p>
                    @endif
                </div>
            @endif
            <a href="{{ route('site.team') }}" class="btn quiet">Conocer al equipo{{ SiteIcon::svg('arrow-right') }}</a>
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

    {{-- Las preguntas principales, sobre una franja celeste entre dos partes blancas; todas están en su página. --}}
    @if ($faqs->isNotEmpty())
        <section class="section split band" aria-labelledby="preguntas">
            <div class="section-head">
                <h2 id="preguntas">Preguntas frecuentes</h2>
            </div>
            @include('site.partials.faqs', ['faqs' => $faqs])
            <p><a href="{{ route('site.faq') }}" class="arrow">Ver todas las preguntas</a></p>
        </section>
    @endif

    @include('site.partials.contact')
</x-site-layout>
