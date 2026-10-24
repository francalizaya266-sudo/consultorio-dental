{{-- Estrellas de Google: el puntaje que copiaron en Ajustes, con enlace a las opiniones si lo hay. --}}
@php
    $tag = $rating['url'] ? 'a' : 'p';
    $score = number_format($rating['rating'], 1, ',', '');
    $count = $rating['count'] ? ' · '.$rating['count'].' '.($rating['count'] === 1 ? 'opinión' : 'opiniones') : '';
@endphp
<{{ $tag }} class="rating" @if ($rating['url']) href="{{ $rating['url'] }}" rel="noopener" target="_blank" @endif>
    <span class="rating-stars" style="--rating: {{ $rating['rating'] }}" aria-hidden="true"></span>
    <span><strong>{{ $score }}</strong> de 5 en Google{{ $count }}</span>
</{{ $tag }}>
