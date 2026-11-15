{{--
    La franja que cierra una página, sobre el pie: su frase (PageTexts) y su botón, a reservar o al WhatsApp.
    closing: lo que devuelve PageTexts::closing(). message: con qué empieza el WhatsApp, si el botón va ahí.
--}}
@php
    use App\Services\Web\ClinicInfo;
    use App\Support\SiteArt;
    use App\Support\SiteIcon;
@endphp
<div class="footer-cta">
    <img src="{{ SiteArt::url('diente-320') }}" alt="" width="320" height="320" loading="lazy" decoding="async">
    <p><strong>{{ $closing['title'] }}</strong> {{ $closing['text'] }}</p>
    @if ($closing['whatsapp'])
        <a href="{{ app(ClinicInfo::class)->whatsappUrl($message) }}" class="btn" rel="noopener">{{ SiteIcon::svg('whatsapp') }}Escribir por WhatsApp</a>
    @else
        <a href="{{ route('site.booking') }}" class="btn">Reservar cita{{ SiteIcon::svg('arrow-right') }}</a>
    @endif
</div>
