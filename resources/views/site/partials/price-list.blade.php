@php
    use App\Support\SiteArt;
    use App\Support\SiteIcon;
@endphp
{{-- Una tarjeta por servicio: su imagen, el nombre, el precio "desde", para qué sirve, cuánto dura y el paso a su página. --}}
<ul class="price-list">
    @foreach ($treatments as $treatment)
        <li>
            <a href="{{ route('site.treatment', $treatment->slug) }}">
                <span class="icon">{{ SiteArt::treatment($treatment, 120) }}</span>
                <span class="name">{{ $treatment->name }}</span>
                @if ($price = $treatment->webPrice())
                    <span class="price">{{ $price }}</span>
                @else
                    <span class="price ask">Precio en la evaluación</span>
                @endif
                @if ($treatment->summary)
                    <span class="desc">{{ $treatment->summary }}</span>
                @endif
                <span class="meta">{{ $treatment->duration_minutes }} min aprox.{{ $treatment->sessionsText() ? ' · '.$treatment->sessionsText() : '' }}</span>
                <span class="more">Saber más{{ SiteIcon::svg('arrow-right') }}</span>
            </a>
        </li>
    @endforeach
</ul>
