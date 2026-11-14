{{-- La cita que llega por el enlace de WhatsApp. Sin la barra de abajo: aquí los botones son los de la cita. --}}
<x-site-layout title="Tu cita" :noindex="true" :whatsapp-float="false">
    <section class="stack-lg">
        <div class="stack">
            <h1>Hola, {{ $appointment->patient->first_name }}</h1>
            @include('site.partials.appointment-card', [
                'confirmUrl' => route('patient.link.confirm', $token),
                'changeUrl' => route('patient.link.change', $token),
                'cancelUrl' => route('patient.link.cancel', $token),
                'pendingRequest' => $pendingRequest,
                'solo' => true,
            ])
        </div>

        <p class="muted small">¿Tienes más citas o familiares en este celular? Míralos en <a href="{{ route('site.account') }}">Mi cuenta</a>.</p>
    </section>
</x-site-layout>
