{{-- Quien está en tratamiento no reserva por la web: su doctor programa sus sesiones. --}}
@php($whatsapp = app(\App\Services\Web\ClinicInfo::class)->whatsappUrl('Hola, estoy en tratamiento y necesito un cambio en mis citas.'))
<div class="flash info" role="status">
    <p>
        {{ $person->relationship === \App\Enums\PatientRelationship::Self
            ? $person->first_name.', como estás en tratamiento, tu doctor programa tus próximas sesiones.'
            : $person->first_name.' está en tratamiento: su doctor programa sus próximas sesiones.' }}
        @if ($whatsapp)
            <a href="{{ $whatsapp }}" rel="noopener">Escríbenos por WhatsApp</a> si necesitas un cambio.
        @else
            Escríbenos si necesitas un cambio.
        @endif
    </p>
    @if ($others)
        <p class="muted small">Si la cita es para otra persona, sigue: la eliges en el último paso.</p>
    @endif
</div>
