@php
    use App\Enums\AppointmentStatus;
    use App\Support\Dates;

    $status = $appointment->status;
@endphp
<x-site-layout title="Tu cita" :noindex="true">
    <section class="stack-lg">
        <div class="stack">
            <h1>Hola, {{ $appointment->patient->first_name }}</h1>
            <div class="appointment">
                <div class="spread">
                    <span class="when">{{ Dates::day($appointment->starts_at->toImmutable()) }}, {{ Dates::time($appointment->starts_at->format('H:i')) }}</span>
                </div>
                <dl class="facts">
                    <dt>Tratamiento</dt>
                    <dd>{{ $appointment->treatment->name }}</dd>
                    <dt>Doctor</dt>
                    <dd>{{ $appointment->doctor->name }}</dd>
                    <dt>Estado</dt>
                    <dd>
                        <span @class(['badge', 'ok' => $status === AppointmentStatus::Confirmed, 'bad' => $status === AppointmentStatus::Cancelled, 'quiet' => in_array($status, [AppointmentStatus::Completed, AppointmentStatus::NoShow], true)])>
                            {{ $status->getLabel() }}
                        </span>
                    </dd>
                </dl>
            </div>
        </div>

        @if ($pendingRequest)
            <div class="flash info">
                Pediste cambiar esta cita a:
                {{ $pendingRequest->options->map(fn ($o) => mb_strtolower(Dates::day($o->starts_at->toImmutable())).' a las '.Dates::time($o->starts_at->format('H:i')))->join('; ', ' o ') }}
                Te avisaremos por WhatsApp cuál quedó.
            </div>
        @endif

        @if ($canChange)
            <div class="stack">
                @if ($status === AppointmentStatus::Pending)
                    <form method="POST" action="{{ route('patient.link.confirm', $token) }}">
                        @csrf
                        <button type="submit" class="btn block">Confirmar mi asistencia</button>
                    </form>
                @endif

                <a href="{{ route('patient.link.change', $token) }}" class="btn quiet block">Cambiar el horario</a>

                @if ($canCancel)
                    <details class="more">
                        <summary class="btn danger block">Cancelar la cita</summary>
                        <form method="POST" action="{{ route('patient.link.cancel', $token) }}" class="stack" style="margin-top: 12px">
                            @csrf
                            <div class="field">
                                <label for="reason">Motivo (opcional)</label>
                                <textarea id="reason" name="reason" class="input" rows="2" maxlength="250"></textarea>
                            </div>
                            <button type="submit" class="btn danger block">Sí, cancelar mi cita</button>
                        </form>
                    </details>
                @else
                    <p class="muted">Faltan menos de {{ $cancelLimitHours }} horas para tu cita. Para cancelarla, escríbenos por WhatsApp.</p>
                @endif
            </div>
        @endif

        <p class="muted small">¿Tienes más citas o familiares en este celular? Míralos en <a href="{{ route('site.account') }}">Mi cuenta</a>.</p>
    </section>
</x-site-layout>
