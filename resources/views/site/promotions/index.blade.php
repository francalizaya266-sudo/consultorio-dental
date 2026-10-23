@php
    use App\Services\Web\PublicImages;
    use App\Support\CampaignText;
@endphp
<x-site-layout title="Promociones" :wide="true" :json-ld="$jsonLd" description="Promociones vigentes del consultorio. Reserva en línea con tu código.">
    <section class="page-head">
        <h1>Promociones</h1>
        <p class="lead">Las promociones se aplican al pagar en el consultorio. Reserva desde aquí y el código queda guardado en tu cita.</p>
    </section>

    @if ($campaigns->isEmpty())
        <p class="panel">Ahora no tenemos promociones vigentes. Síguenos en redes o escríbenos por WhatsApp para enterarte de las próximas.</p>
    @else
        <div class="promos">
            @foreach ($campaigns as $campaign)
                <a href="{{ route('site.promotion', $campaign->slug) }}" class="promo-card">
                    @if ($campaign->image_path)
                        <img src="{{ PublicImages::url($campaign->image_path) }}" alt="" loading="lazy">
                    @endif
                    <span class="body">
                        <strong>{{ $campaign->name }}</strong>
                        @if ($discount = CampaignText::discount($campaign))
                            <span class="discount">{{ $discount }}</span>
                        @endif
                        @if ($campaign->summary)
                            <span>{{ $campaign->summary }}</span>
                        @endif
                        <span class="until">{{ CampaignText::until($campaign) }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</x-site-layout>
