@php
    use App\Services\Web\ClinicInfo;
    use App\Support\Phone;
    use App\Support\SiteIcon;

    $clinic = app(ClinicInfo::class);
    $hours = $clinic->hours();
    $headingLevel ??= 'h2';
    $heading ??= 'Dónde estamos';
    $map = $clinic->mapEmbedUrl();
@endphp
{{--
    Dos tarjetas: a la izquierda, la dirección, el horario y cada forma de escribir o llamar con su botón; a la
    derecha, el mapa. "Cómo llegar" sale una sola vez: al pie del mapa o, sin mapa, con los demás botones.
--}}
<section class="section contact" aria-labelledby="contacto">
    <div class="contact-info">
        <{{ $headingLevel }} id="contacto"><span class="badge">{{ SiteIcon::svg('pin') }}</span>{{ $heading }}</{{ $headingLevel }}>
        @if ($clinic->fullAddress())
            <div class="fact">
                {{ SiteIcon::svg('pin') }}
                <p class="address">{{ $clinic->fullAddress() }}</p>
            </div>
        @endif

        @unless ($hours->isEmpty())
            <div class="fact">
                {{ SiteIcon::svg('clock') }}
                <div>
                    <p class="label">Horario de atención</p>
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
                </div>
            </div>
        @endunless

        {{-- Cada forma de comunicarse, con su botón. Sin teléfono fijo, se llama al celular del WhatsApp. --}}
        @if ($clinic->whatsappUrl() || $clinic->callHref() || $clinic->email() || (! $map && $clinic->directionsUrl()))
            <ul class="ways">
                @if ($clinic->whatsappUrl())
                    <li>
                        <span class="way-icon whatsapp">{{ SiteIcon::svg('whatsapp') }}</span>
                        <p><strong>WhatsApp</strong><span>{{ Phone::display($clinic->whatsapp()) }}</span></p>
                        <a href="{{ $clinic->whatsappUrl() }}" class="pill" rel="noopener" aria-label="Escribir por WhatsApp">Escribir</a>
                    </li>
                @endif
                @if ($clinic->callHref())
                    <li>
                        <span class="way-icon">{{ SiteIcon::svg('phone') }}</span>
                        <p><strong>Teléfono</strong><span>{{ $clinic->phone() ?? Phone::display($clinic->whatsapp()) }}</span></p>
                        <a href="{{ $clinic->callHref() }}" class="pill" aria-label="Llamar al consultorio">Llamar</a>
                    </li>
                @endif
                @if ($clinic->email())
                    <li>
                        <span class="way-icon">{{ SiteIcon::svg('mail') }}</span>
                        <p><strong>Correo</strong><span>{{ $clinic->email() }}</span></p>
                        <a href="mailto:{{ $clinic->email() }}" class="pill">Enviar correo</a>
                    </li>
                @endif
                @if (! $map && $clinic->directionsUrl())
                    <li>
                        <span class="way-icon">{{ SiteIcon::svg('pin') }}</span>
                        <p><strong>Cómo llegar</strong><span>En Google Maps</span></p>
                        <a href="{{ $clinic->directionsUrl() }}" class="pill" rel="noopener" target="_blank">Cómo llegar</a>
                    </li>
                @endif
            </ul>
        @endif
    </div>

    @if ($map)
        <div class="map-block">
            <div class="map">
                <iframe src="{{ $map }}" title="Mapa: {{ $clinic->fullAddress() }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
            </div>
            @if ($clinic->directionsUrl())
                <div class="map-foot">
                    <p><strong>{{ $clinic->name() }}</strong><span>{{ $clinic->fullAddress() }}</span></p>
                    <a href="{{ $clinic->directionsUrl() }}" class="pill" rel="noopener" target="_blank">Cómo llegar</a>
                </div>
            @endif
        </div>
    @endif
</section>
