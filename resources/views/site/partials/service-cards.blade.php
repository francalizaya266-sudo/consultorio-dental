{{-- Los tratamientos del inicio: su imagen arriba, el nombre, el precio "desde" y para qué sirve. --}}
<ul class="services-grid">
    @foreach ($treatments as $treatment)
        <li>
            <a href="{{ route('site.treatment', $treatment->slug) }}">
                <span class="art">{{ \App\Support\SiteArt::treatment($treatment, 112) }}</span>
                <span class="name">{{ $treatment->name }}</span>
                <span class="price">{{ $treatment->webPrice() ?? 'Precio en la evaluación' }}</span>
                @if ($treatment->summary)
                    <span class="desc">{{ $treatment->summary }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>
