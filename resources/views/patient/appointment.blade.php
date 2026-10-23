@php
    use App\Enums\AppointmentStatus;
    $status = $appointment->status;
@endphp
<x-patient-layout title="Tu cita">
    <div class="card">
        <h1>Hola, {{ $appointment->patient->first_name }}</h1>

        <dl>
            <dt>Tratamiento</dt>
            <dd>{{ $appointment->treatment->name }}</dd>
            <dt>Día y hora</dt>
            <dd>{{ ucfirst($appointment->starts_at->translatedFormat('l j \d\e F')) }}, {{ $appointment->starts_at->format('g:i a') }}</dd>
            <dt>Doctor</dt>
            <dd>{{ $appointment->doctor->name }}</dd>
            <dt>Estado</dt>
            <dd>
                <span @class(['badge', 'ok' => $status === AppointmentStatus::Confirmed, 'bad' => $status === AppointmentStatus::Cancelled])>
                    {{ $status->getLabel() }}
                </span>
            </dd>
        </dl>

        @if ($canChange)
            <div class="actions">
                @if ($status === AppointmentStatus::Pending)
                    <form method="POST" action="{{ route('patient.link.confirm', $token) }}">
                        @csrf
                        <button type="submit" class="primary">Confirmar mi asistencia</button>
                    </form>
                @endif

                @if ($canCancel)
                    <details>
                        <summary class="button danger">Cancelar la cita</summary>
                        <form method="POST" action="{{ route('patient.link.cancel', $token) }}">
                            @csrf
                            <textarea name="reason" rows="2" maxlength="250" placeholder="Motivo (opcional)"></textarea>
                            <button type="submit" class="danger">Sí, cancelar mi cita</button>
                        </form>
                    </details>
                @else
                    <p class="note">Faltan menos de {{ $cancelLimitHours }} horas para tu cita. Para cancelarla o moverla, escríbenos por WhatsApp.</p>
                @endif
            </div>
        @endif
    </div>
</x-patient-layout>
