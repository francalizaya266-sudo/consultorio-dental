@php
    use App\Enums\AppointmentStatus;
    use App\Support\Dates;
    use App\Support\Phone;

    $holder = $patients->firstWhere('relationship', \App\Enums\PatientRelationship::Self);
@endphp
<x-site-layout title="Mi cuenta" :noindex="true">
    <section class="stack-lg">
        <div class="stack">
            <h1>Hola{{ $holder ? ', '.$holder->first_name : '' }}</h1>
            <p class="lead">Citas de {{ $patients->count() > 1 ? 'tu familia' : 'este celular' }} · {{ Phone::display($account->phone) }}</p>
        </div>

        <section class="stack" aria-labelledby="proximas">
            <div class="spread">
                <h2 id="proximas">Próximas citas</h2>
                <a href="{{ route('site.booking') }}" class="btn small">Reservar otra</a>
            </div>

            @forelse ($upcoming as $appointment)
                @php($status = $appointment->status)
                <article class="appointment">
                    <div class="spread">
                        <span class="when">{{ Dates::day($appointment->starts_at->toImmutable()) }}, {{ Dates::time($appointment->starts_at->format('H:i')) }}</span>
                        <span @class(['badge', 'ok' => $status === AppointmentStatus::Confirmed, 'quiet' => ! in_array($status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)])>{{ $status->getLabel() }}</span>
                    </div>
                    <p><strong>{{ $appointment->patient->first_name }}</strong> · {{ $appointment->treatment->name }} · {{ $appointment->doctor->name }}</p>

                    @if ($policy->canChange($appointment))
                        <div class="actions">
                            @if ($status === AppointmentStatus::Pending)
                                <form method="POST" action="{{ route('site.account.confirm', $appointment) }}">
                                    @csrf
                                    <button type="submit" class="btn small">Confirmar asistencia</button>
                                </form>
                            @endif
                            <a href="{{ route('site.account.change', $appointment) }}" class="btn small quiet" style="background: var(--ground)">Cambiar horario</a>
                            @if ($policy->canSelfCancel($appointment))
                                <details class="more" style="flex-basis: 100%">
                                    <summary>Cancelar esta cita</summary>
                                    <form method="POST" action="{{ route('site.account.cancel', $appointment) }}" class="stack" style="margin-top: 8px">
                                        @csrf
                                        <textarea name="reason" class="input" rows="2" maxlength="250" placeholder="Motivo (opcional)" aria-label="Motivo de la cancelación"></textarea>
                                        <button type="submit" class="btn small danger">Sí, cancelar</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="panel">
                    <p>No tienes citas próximas.</p>
                </div>
            @endforelse
        </section>

        <section class="stack" aria-labelledby="anteriores">
            <h2 id="anteriores">Citas anteriores y datos personales</h2>
            @if ($elevated)
                @forelse ($past as $appointment)
                    <p class="spread">
                        <span>{{ $appointment->starts_at->format('d/m/Y') }} · <strong>{{ $appointment->patient->first_name }}</strong> · {{ $appointment->treatment->name }}</span>
                        <span class="badge quiet">{{ $appointment->status->getLabel() }}</span>
                    </p>
                @empty
                    <p class="muted">Todavía no hay citas anteriores.</p>
                @endforelse

                <div class="stack" style="margin-top: 24px">
                    @foreach ($patients as $patient)
                        <div class="panel">
                            <h3>{{ $patient->full_name }}</h3>
                            <dl class="facts" style="margin-top: 8px">
                                <dt>Parentesco</dt>
                                <dd>{{ $patient->relationship->getLabel() }}</dd>
                                <dt>Documento</dt>
                                <dd>{{ $patient->document_number ?: '—' }}</dd>
                                <dt>Nacimiento</dt>
                                <dd>{{ $patient->birth_date?->format('d/m/Y') ?? '—' }}</dd>
                                <dt>Correo</dt>
                                <dd>{{ $patient->email ?: '—' }}</dd>
                            </dl>
                        </div>
                    @endforeach
                    <p class="muted small">¿Algún dato está mal? Escríbenos por WhatsApp y lo corregimos.</p>
                </div>
            @else
                <p>Para cuidar tus datos, primero confirmamos que eres tú: te enviaremos un enlace por WhatsApp.</p>
                <form method="POST" action="{{ route('site.account.verify') }}">
                    @csrf
                    <button type="submit" class="btn quiet block">Enviarme el enlace</button>
                </form>
            @endif
        </section>

        <form method="POST" action="{{ route('site.account.logout') }}">
            @csrf
            <button type="submit" class="link-btn">Salir en este celular</button>
        </form>
    </section>
</x-site-layout>
