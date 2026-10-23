@php
    use App\Services\Web\ClinicInfo;
    use App\Services\Web\PublicImages;
    use App\Support\CampaignText;
    use App\Support\Markdown;
    use App\Support\Money;

    $clinic = app(ClinicInfo::class);
    $title = $treatment->seo_title ?: $treatment->name.($clinic->district() ? ' en '.$clinic->district() : '');
    $direct = $bookAs?->is($treatment);
    $campaignPrice = $campaign?->priceFor($treatment);
@endphp
<x-site-layout :title="$title" :wide="true" :json-ld="$jsonLd" :image="PublicImages::url($treatment->image_path)"
    :description="$treatment->seo_description ?: $treatment->summary">
    <nav class="crumbs" aria-label="Estás en"><a href="{{ route('site.treatments') }}">Tratamientos</a></nav>

    {{-- A la derecha, lo más útil: los próximos horarios libres; si no hay, la foto. --}}
    <section @class(['treatment-head', 'with-aside' => $nextFree || $treatment->image_path])>
        <div>
            <h1>{{ $treatment->name }}</h1>
            @if ($treatment->summary)
                <p class="lead">{{ $treatment->summary }}</p>
            @endif

            <dl class="facts-row">
                <div>
                    <dt>Duración</dt>
                    <dd>{{ $treatment->duration_minutes }} min aprox.</dd>
                </div>
                @if ($treatment->sessionsText())
                    <div>
                        <dt>Sesiones</dt>
                        <dd>{{ $treatment->sessionsText() }}</dd>
                    </div>
                @endif
                <div>
                    <dt>{{ $campaign ? 'Precio con la campaña' : 'Precio' }}</dt>
                    <dd>
                        @if ($campaign)
                            <s class="was">{{ $treatment->webPrice() }}</s>
                            {{ $campaignPrice > 0 ? 'desde '.Money::short($campaignPrice) : 'Gratis' }}
                        @else
                            {{ $treatment->webPrice() ?? 'En tu evaluación' }}
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($campaign)
                <p class="promo-note">Campaña <a href="{{ route('site.promotion', $campaign->slug) }}">{{ $campaign->name }}</a> · {{ mb_strtolower(CampaignText::until($campaign)) }}.</p>
            @endif

            <div class="row">
                @if ($bookAs)
                    <a href="{{ route('site.booking', array_filter(['tratamiento' => $bookAs->slug, 'promo' => $direct ? $campaign?->slug : null])) }}" class="btn">{{ $direct ? ($campaign ? 'Reservar con la campaña' : 'Reservar cita') : 'Reservar evaluación' }}</a>
                @endif
                @if ($clinic->whatsappUrl())
                    <a href="{{ $clinic->whatsappUrl('Hola, quisiera información sobre '.mb_strtolower($treatment->name).'.') }}" class="btn quiet" rel="noopener">Preguntar por WhatsApp</a>
                @endif
            </div>
            @unless ($direct)
                <p class="muted small">Este tratamiento se programa después de una evaluación: el doctor revisa tu caso, te da el presupuesto y agenda las sesiones contigo.</p>
            @endunless
            @if ($treatment->webPrice())
                <p class="muted small">{{ CampaignText::HOW_TO_PAY }}</p>
            @endif
        </div>
        @if ($nextFree)
            @include('site.partials.next-free', ['treatment' => $bookAs, 'nextFree' => $nextFree, 'promo' => $direct ? $campaign?->slug : null])
        @elseif ($treatment->image_path)
            <img src="{{ PublicImages::url($treatment->image_path) }}" alt="{{ $treatment->name }}" class="treatment-photo">
        @endif
    </section>

    @php($photoBelow = $nextFree && $treatment->image_path)
    @if (filled($treatment->description) || $photoBelow)
        <section @class(['section', 'treatment-body', 'with-image' => $photoBelow])>
            @if (filled($treatment->description))
                <div class="prose">{{ Markdown::toHtml($treatment->description) }}</div>
            @endif
            @if ($photoBelow)
                <img src="{{ PublicImages::url($treatment->image_path) }}" alt="{{ $treatment->name }}" class="treatment-photo" loading="lazy">
            @endif
        </section>
    @endif

    @if ($cases->isNotEmpty())
        <section class="section" aria-labelledby="casos">
            <h2 id="casos">Resultados de pacientes</h2>
            @include('site.partials.cases', ['cases' => $cases])
        </section>
    @endif

    @if ($doctors->isNotEmpty())
        <section class="section" aria-labelledby="quien">
            <h2 id="quien">Quién lo realiza</h2>
            @include('site.partials.doctors', ['doctors' => $doctors, 'full' => false])
        </section>
    @endif

    @if ($reviews->isNotEmpty())
        <section class="section" aria-labelledby="opiniones">
            <h2 id="opiniones">Opiniones</h2>
            @include('site.partials.reviews', ['reviews' => $reviews])
        </section>
    @endif

    @if ($faqs->isNotEmpty())
        <section class="section" aria-labelledby="preguntas">
            <h2 id="preguntas">Preguntas sobre {{ mb_strtolower($treatment->name) }}</h2>
            @include('site.partials.faqs', ['faqs' => $faqs])
        </section>
    @endif

    @if ($others->isNotEmpty())
        <section class="section" aria-labelledby="otros">
            <h2 id="otros">Otros tratamientos</h2>
            @include('site.partials.price-list', ['treatments' => $others])
        </section>
    @endif
</x-site-layout>
