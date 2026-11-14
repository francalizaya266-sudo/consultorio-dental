@php
    use App\Services\Web\ClinicInfo;
    use App\Support\Phone;

    $clinic = app(ClinicInfo::class);
    $hours = $clinic->hours();
    $headingLevel ??= 'h2';
    $heading ??= 'Dónde estamos';
    $map = $clinic->mapEmbedUrl();
@endphp
{{-- "Cómo llegar" sale una sola vez: bajo el mapa, o con los demás botones si no hay mapa. --}}
<section class="section contact" aria-labelledby="contacto">
    <div class="contact-info">
        <{{ $headingLevel }} id="contacto">{{ $heading }}</{{ $headingLevel }}>
        @if ($clinic->fullAddress())
            <p class="address">{{ $clinic->fullAddress() }}</p>
        @endif

        @unless ($hours->isEmpty())
            @if ($status = $hours->status(now()->toImmutable()))
                <p @class(['status', 'open' => $hours->isOpen(now()->toImmutable())])>{{ $status }}</p>
            @endif
            <dl class="hours">
                @foreach ($hours->lines() as $line)
                    <dt>{{ $line['days'] }}</dt>
                    <dd>@foreach ($line['hours'] as $range)<span>{{ $range }}</span>@endforeach</dd>
                @endforeach
            </dl>
            @if ($clinic->hoursNote())
                <p class="muted small">{{ $clinic->hoursNote() }}</p>
            @endif
        @endunless

        <div class="row">
            @if ($clinic->whatsappUrl())
                <a href="{{ $clinic->whatsappUrl() }}" class="btn whatsapp" rel="noopener">WhatsApp {{ Phone::display($clinic->whatsapp()) }}</a>
            @endif
            @if ($clinic->phone())
                <a href="{{ $clinic->phoneHref() }}" class="btn quiet">Llamar al {{ $clinic->phone() }}</a>
            @endif
            @if (! $map && $clinic->directionsUrl())
                <a href="{{ $clinic->directionsUrl() }}" class="btn quiet" rel="noopener" target="_blank">Cómo llegar</a>
            @endif
        </div>
        {{-- El correo estaba solo en el pie, que en estas páginas ya no repite el contacto. --}}
        @if ($clinic->email())
            <p class="muted small">Correo: <a href="mailto:{{ $clinic->email() }}">{{ $clinic->email() }}</a></p>
        @endif
    </div>

    @if ($map)
        <div class="map-block">
            <div class="map">
                <iframe src="{{ $map }}" title="Mapa: {{ $clinic->fullAddress() }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
            </div>
            @if ($clinic->directionsUrl())
                <a href="{{ $clinic->directionsUrl() }}" class="btn quiet" rel="noopener" target="_blank">Cómo llegar</a>
            @endif
        </div>
    @endif
</section>
