@php
    use App\Services\Web\ClinicInfo;

    $clinic = app(ClinicInfo::class);
@endphp
<x-site-layout page="contacto" title="Contacto y cómo llegar" :wide="true" :json-ld="$jsonLd" :footer-contact="false"
    :description="'Dirección, horario de atención, WhatsApp y mapa'.($clinic->fullAddress() ? ': '.$clinic->fullAddress() : '').'.'">
    @include('site.partials.contact', ['heading' => 'Nuestra ubicación'])
</x-site-layout>
