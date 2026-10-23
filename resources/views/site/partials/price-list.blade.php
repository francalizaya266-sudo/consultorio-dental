{{-- Tarifario: nombre, para qué sirve, duración y precio desde. --}}
<ul class="price-list">
    @foreach ($treatments as $treatment)
        <li>
            <a href="{{ route('site.treatment', $treatment->slug) }}">
                <span class="name">{{ $treatment->name }}</span>
                <span class="price">{{ $treatment->webPrice() ?? 'Precio en la evaluación' }}</span>
                @if ($treatment->summary)
                    <span class="desc">{{ $treatment->summary }}</span>
                @endif
                <span class="meta">{{ $treatment->duration_minutes }} min aprox.{{ $treatment->sessionsText() ? ' · '.$treatment->sessionsText() : '' }}</span>
            </a>
        </li>
    @endforeach
</ul>
