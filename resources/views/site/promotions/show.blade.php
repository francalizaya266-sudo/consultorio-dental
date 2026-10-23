@php
    use App\Services\Web\ClinicInfo;
    use App\Services\Web\PublicImages;
    use App\Support\CampaignText;
    use App\Support\Markdown;

    $clinic = app(ClinicInfo::class);
    $discount = CampaignText::discount($campaign);
    $bookingUrl = route('site.booking', array_filter([
        'tratamiento' => $campaign->treatment?->online_bookable ? $campaign->treatment->slug : null,
        'codigo' => $campaign->promo_code,
        'origen' => 'campaign',
    ]));
@endphp
<x-site-layout :title="$campaign->name" :wide="true" :json-ld="$jsonLd" :image="PublicImages::url($campaign->image_path)"
    :description="$campaign->summary ?: ($discount ? $discount.' · '.CampaignText::until($campaign) : null)">
    <nav class="crumbs" aria-label="Estás en"><a href="{{ route('site.promotions') }}">Promociones</a></nav>

    <article @class(['promo-page', 'with-image' => $campaign->image_path])>
        @if ($campaign->image_path)
            <img src="{{ PublicImages::url($campaign->image_path) }}" alt="{{ $campaign->name }}" class="promo-image">
        @endif
        <div class="stack">
            <h1>{{ $campaign->name }}</h1>
            @if ($discount)
                <p class="discount-big">{{ $discount }}</p>
            @endif
            @if ($campaign->summary)
                <p class="lead">{{ $campaign->summary }}</p>
            @endif
            <p class="until">{{ CampaignText::until($campaign) }}@if ($campaign->treatment) · <a href="{{ route('site.treatment', $campaign->treatment->slug) }}">{{ $campaign->treatment->name }}</a>@endif</p>

            @if ($campaign->hasSlotsLeft())
                @if ($campaign->promo_code)
                    <p class="code">Código: <strong>{{ $campaign->promo_code }}</strong></p>
                @endif
                <div class="row">
                    <a href="{{ $bookingUrl }}" class="btn">Reservar con esta promoción</a>
                    @if ($clinic->whatsappUrl())
                        <a href="{{ $clinic->whatsappUrl($campaign->whatsapp_text ?: 'Hola, quiero la promoción "'.$campaign->name.'".') }}" class="btn quiet" rel="noopener">Preguntar por WhatsApp</a>
                    @endif
                </div>
            @else
                <p class="flash info">Los cupos de esta promoción ya se agotaron. Escríbenos y te contamos qué otras opciones hay.</p>
            @endif

            @if (filled($campaign->description))
                <div class="prose">{{ Markdown::paragraphs($campaign->description) }}</div>
            @endif
            <p class="muted small">El descuento se aplica al pagar en el consultorio. No es acumulable con otras promociones salvo que se indique.</p>
        </div>
    </article>
</x-site-layout>
