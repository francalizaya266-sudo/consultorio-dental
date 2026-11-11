{{--
    Lo primero para quien vuelve desde su celular recordado: lo suyo, con el botón de lo que sigue.
    Sin montos ni pagos: eso sigue en Mi cuenta, después de verificar por WhatsApp.
--}}
@php
    use App\Enums\AppointmentStatus;
    use App\Enums\PatientRelationship;
    use App\Support\Dates;

    $policy = app(\App\Services\Booking\PatientChangePolicy::class);
    $isSelf = fn ($patient) => $patient->relationship === PatientRelationship::Self;
    $askedHealth = [];
    $nothing = $welcome['appointments']->isEmpty() && $welcome['sessions']->isEmpty() && $welcome['checkups']->isEmpty();
@endphp
<section class="welcome" aria-labelledby="bienvenida">
    <div class="welcome-head">
        <h2 id="bienvenida">Hola{{ $welcome['name'] ? ', '.$welcome['name'] : '' }}</h2>
        <a href="{{ route('site.account') }}" class="arrow">Mi cuenta</a>
    </div>

    @if ($nothing)
        <p class="welcome-empty">No tienes citas por delante. Cuando quieras, reserva aquí abajo.</p>
    @else
        <div class="welcome-cards">
            @foreach ($welcome['appointments'] as $appointment)
                @php($pending = $appointment->status === AppointmentStatus::Pending)
                <article class="welcome-card" data-welcome="appointment">
                    <span class="welcome-kicker">{{ $isSelf($appointment->patient) ? 'Tu próxima cita' : 'Cita de '.$appointment->patient->first_name }}</span>
                    <strong class="welcome-when">{{ Dates::day($appointment->starts_at->toImmutable()) }}, {{ Dates::time($appointment->starts_at->format('H:i')) }}</strong>
                    <p>{{ $appointment->treatment->name }} · {{ $appointment->doctor->name }}</p>
                    @if ($appointment->patient->medicalBackground === null && ! in_array($appointment->patient_id, $askedHealth, true))
                        @php($askedHealth[] = $appointment->patient_id)
                        @include('site.partials.health-ask', ['patient' => $appointment->patient])
                    @endif
                    @if ($policy->canChange($appointment))
                        <div class="welcome-actions">
                            @if ($pending)
                                <form method="POST" action="{{ route('site.account.confirm', $appointment) }}">
                                    @csrf
                                    <button type="submit" class="btn small">Confirmar</button>
                                </form>
                            @else
                                <span class="badge ok">Confirmada</span>
                            @endif
                            <a href="{{ route('site.account.change', $appointment) }}" class="btn small quiet">Cambiar horario</a>
                        </div>
                    @endif
                </article>
            @endforeach

            @foreach ($welcome['sessions'] as ['patient' => $patient, 'done' => $done, 'total' => $total, 'doctor' => $doctor, 'requested' => $requested])
                @php($left = $total - $done)
                <article class="welcome-card" data-welcome="sessions">
                    <span class="welcome-kicker">{{ $isSelf($patient) ? 'Tu tratamiento' : 'Tratamiento de '.$patient->first_name }}</span>
                    <strong class="welcome-when">{{ $isSelf($patient) ? 'Te '.($left === 1 ? 'falta' : 'faltan') : 'A '.$patient->first_name.' le '.($left === 1 ? 'falta' : 'faltan') }} {{ $left }} de {{ $total }} {{ $total === 1 ? 'sesión' : 'sesiones' }}</strong>
                    <div class="meter" role="img" aria-label="{{ $done }} de {{ $total }} sesiones hechas"><span style="width: {{ round($done / $total * 100) }}%"></span></div>
                    <p class="muted">{{ $doctor ? 'Con '.$doctor.'. ' : '' }}Cada sesión la programa {{ $isSelf($patient) ? 'tu' : 'su' }} doctor: pídela y te escribimos con el horario.</p>
                    <div class="welcome-actions">
                        @if ($requested)
                            <p class="welcome-done">Ya la pediste. Te escribiremos por WhatsApp con el horario.</p>
                        @else
                            <form method="POST" action="{{ route('site.account.session', $patient) }}">
                                @csrf
                                <button type="submit" class="btn small">{{ $isSelf($patient) ? 'Pedir mi próxima sesión' : 'Pedir su próxima sesión' }}</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach

            @foreach ($welcome['checkups'] as ['patient' => $patient, 'months' => $months, 'url' => $url])
                <article class="welcome-card" data-welcome="checkup">
                    <span class="welcome-kicker">{{ $isSelf($patient) ? 'Tu control' : 'Control de '.$patient->first_name }}</span>
                    <strong class="welcome-when">{{ $months }} {{ $months === 1 ? 'mes' : 'meses' }} desde {{ $isSelf($patient) ? 'tu' : 'su' }} última visita</strong>
                    <p class="muted">Ya {{ $isSelf($patient) ? 'te' : 'le' }} toca una revisión: así lo pequeño se trata antes de que duela.</p>
                    <div class="welcome-actions">
                        <a href="{{ $url }}" class="btn small">{{ $isSelf($patient) ? 'Reservar mi control' : 'Reservar su control' }}</a>
                    </div>
                </article>
            @endforeach
        </div>
        @if ($welcome['more'] > 0)
            <p class="welcome-more"><a href="{{ route('site.account') }}" class="arrow">{{ $welcome['more'] === 1 ? 'Ver 1 cita más' : 'Ver '.$welcome['more'].' citas más' }}</a></p>
        @endif
    @endif

    @if ($welcome['name'])
        <form method="POST" action="{{ route('site.account.logout') }}" class="welcome-not-you">
            @csrf
            ¿No eres {{ $welcome['name'] }}? <button type="submit" class="link-btn">Salir en este celular</button>
        </form>
    @endif
</section>
