{{--
    La tarjeta de una cita. Es la misma en el enlace de WhatsApp, en Mi cuenta y en el inicio: mismo orden y
    mismas palabras ("Confirmar asistencia", "Cambiar horario", "Cancelar cita").

    name: de quién es la cita, o null si es del titular del celular ("Tu cita").
    guest: se reservó con este número sin entrar desde el celular de la familia y nadie la confirmó: se le explica al dueño del número.
    confirmUrl, changeUrl: a dónde van los botones (con el enlace de la cita o con el celular recordado).
    cancelUrl: null donde no se ofrece cancelar (el inicio, que solo lleva lo que sigue).
    askHealth: muestra "Antes de la cita" a quien todavía no contó su salud.
    pendingRequest: el pedido de cambio de horario que espera respuesta, donde se conoce.
    solo: la cita es lo único de la página (el enlace de WhatsApp): botones a todo el ancho.
--}}
@php
    use App\Enums\AppointmentStatus;
    use App\Services\Booking\PatientChangePolicy;
    use App\Services\Web\ClinicInfo;
    use App\Support\Dates;

    $policy = app(PatientChangePolicy::class);
    $status = $appointment->status;
    $day = Dates::day($appointment->starts_at->toImmutable());
    $hour = Dates::time($appointment->starts_at->format('H:i'));
    $cancelUrl ??= null;
    $guest ??= false;
    $pendingRequest ??= null;
    $cancelByWhatsapp = app(ClinicInfo::class)->whatsappUrl('Hola, necesito cancelar la cita de '.$appointment->patient->full_name.' del '.mb_strtolower($day).' a las '.$hour.'.');
@endphp
<article @class(['appointment', 'solo' => $solo ?? false]) data-appointment>
    <div class="spread">
        <span class="kicker">{{ ($name ?? null) ? 'Cita de '.$name : 'Tu cita' }}</span>
        {{-- "Pendiente" es la palabra del consultorio; al paciente se le dice lo que le toca hacer. --}}
        <span @class(['badge', 'ok' => $status === AppointmentStatus::Confirmed, 'bad' => $status === AppointmentStatus::Cancelled, 'quiet' => ! in_array($status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Cancelled], true)])>{{ $status === AppointmentStatus::Pending ? 'Por confirmar' : $status->getLabel() }}</span>
    </div>
    <strong class="when">{{ $day }}, {{ $hour }}</strong>
    <p>{{ $appointment->treatment->name }} · {{ $appointment->doctor->name }}</p>

    {{-- La página no pide códigos al reservar: quien recibe una cita que no esperaba tiene que poder decir que no es suya. --}}
    @if ($guest && $status === AppointmentStatus::Pending)
        <p class="note guest">
            <strong>Reservaron esta cita con tu número.</strong>
            Si es de tu familia, confirma la asistencia. Si no la reconoces,
            @if ($cancelUrl)
                cancela la cita.
            @else
                cancélala en <a href="{{ route('site.account') }}">Mi cuenta</a>.
            @endif
        </p>
    @elseif ($askHealth ?? false)
        @include('site.partials.health-ask', ['patient' => $appointment->patient])
    @endif

    @if ($pendingRequest)
        <p class="note">
            Pediste cambiar esta cita a:
            {{ $pendingRequest->options->map(fn ($option) => mb_strtolower(Dates::day($option->starts_at->toImmutable())).' a las '.Dates::time($option->starts_at->format('H:i')))->join('; ', ' o ') }}.
            Te avisaremos por WhatsApp cuál quedó.
        </p>
    @endif

    @if ($policy->canChange($appointment))
        <div class="actions">
            @if ($status === AppointmentStatus::Pending)
                <form method="POST" action="{{ $confirmUrl }}">
                    @csrf
                    <button type="submit" class="btn small">Confirmar asistencia</button>
                </form>
            @endif
            <a href="{{ $changeUrl }}" class="btn small quiet">Cambiar horario</a>
        </div>

        @if ($cancelUrl && $policy->canSelfCancel($appointment))
            <details class="more">
                <summary>Cancelar cita</summary>
                <form method="POST" action="{{ $cancelUrl }}" class="stack">
                    @csrf
                    <div class="field">
                        <label for="reason-{{ $appointment->getKey() }}">Motivo (opcional)</label>
                        <textarea id="reason-{{ $appointment->getKey() }}" name="reason" class="input" rows="2" maxlength="250"></textarea>
                    </div>
                    <button type="submit" class="btn small danger">Sí, cancelar la cita</button>
                </form>
            </details>
        @elseif ($cancelUrl)
            <p class="muted small">
                Faltan menos de {{ $policy->selfCancelMinHours() }} horas para la cita, así que ya no se cancela desde aquí.
                @if ($cancelByWhatsapp)
                    <a href="{{ $cancelByWhatsapp }}" rel="noopener">Escríbenos por WhatsApp</a> y te ayudamos.
                @else
                    Escríbenos por WhatsApp y te ayudamos.
                @endif
            </p>
        @endif
    @endif
</article>
