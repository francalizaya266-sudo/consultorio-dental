<x-site-layout title="Promoción terminada" :noindex="true" :wide="true">
    <section class="page-head">
        <h1>{{ $campaign ? 'Esta promoción ya terminó' : 'No encontramos esta promoción' }}</h1>
        <p class="lead">{{ $campaign ? '“'.$campaign->name.'” ya no está vigente.' : 'Puede que el enlace esté incompleto.' }} Estas son las que siguen activas.</p>
    </section>

    @if ($campaigns->isEmpty())
        <p class="panel">Ahora no tenemos promociones vigentes. Igual puedes <a href="{{ route('site.booking') }}">reservar tu cita</a>.</p>
    @else
        <ul class="choice-list">
            @foreach ($campaigns as $active)
                <li><a href="{{ route('site.promotion', $active->slug) }}" class="choice"><strong>{{ $active->name }}</strong>@if ($active->summary)<span class="meta">{{ $active->summary }}</span>@endif</a></li>
            @endforeach
        </ul>
    @endif
</x-site-layout>
