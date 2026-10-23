@php
    use App\Livewire\Site\ChangeAppointment;
    use App\Services\Booking\RescheduleMode;
    use Carbon\CarbonImmutable;

    $appointment = $this->appointment;
    $automatic = $this->mode === RescheduleMode::Automatic;
@endphp
<div class="stack-lg">
    <a href="{{ $this->backUrl() }}" class="link-btn" style="text-decoration: none">← Volver a mi cita</a>

    <section class="stack" aria-labelledby="cambiar">
        <h1 id="cambiar">Cambiar el horario</h1>
        <div class="summary">
            <p class="what">{{ $appointment->treatment->name }} · {{ $appointment->patient->first_name }}</p>
            <p style="margin: 4px 0 0">Ahora: {{ ChangeAppointment::dayLabel($appointment->starts_at->toImmutable()) }}, {{ ChangeAppointment::timeLabel($appointment->starts_at->format('H:i')) }}</p>
            <p class="muted" style="margin: 2px 0 0">con {{ $appointment->doctor->name }}</p>
        </div>

        @if (! $this->canChange)
            <div class="flash info">Esta cita ya no se puede cambiar desde aquí. Escríbenos por WhatsApp y te ayudamos.</div>
        @else
            @if ($automatic)
                <p class="lead">Elige el nuevo día y hora. El cambio se hace al instante.</p>
            @else
                <p class="lead">
                    {{ $appointment->fixed_date ? 'Esta sesión tiene fecha coordinada con tu doctor' : 'Falta poco para tu cita' }},
                    así que el consultorio confirma el cambio. Elige hasta {{ $this->maxOptions() }} horarios que te sirvan y te avisaremos cuál quedó.
                </p>
                @if ($this->pendingRequest)
                    <div class="flash info">
                        Ya pediste cambiarla a:
                        {{ $this->pendingRequest->options->map(fn ($o) => mb_strtolower(ChangeAppointment::dayLabel($o->starts_at->toImmutable())).' a las '.ChangeAppointment::timeLabel($o->starts_at->format('H:i')))->join('; ', ' o ') }}
                        Si envías otro pedido, reemplaza al anterior.
                    </div>
                @endif
            @endif

            <div wire:loading.delay class="loading-line" aria-hidden="true"></div>

            @if ($error)
                <div class="flash bad" role="alert">{{ $error }}</div>
            @endif

            @include('livewire.site.partials.slot-picker', ['action' => 'pick', 'selectedTimes' => $chosen])

            @if ($chosen !== [])
                <div class="panel stack">
                    <h2>{{ $automatic ? 'Nuevo horario' : 'Tus opciones' }}</h2>
                    <ul class="choice-list">
                        @foreach ($chosen as $key)
                            @php($start = CarbonImmutable::parse($key))
                            <li class="spread">
                                <strong>{{ ChangeAppointment::dayLabel($start) }}, {{ ChangeAppointment::timeLabel($start->format('H:i')) }}</strong>
                                @unless ($automatic)
                                    <button type="button" class="link-btn" wire:click="remove('{{ $key }}')">Quitar</button>
                                @endunless
                            </li>
                        @endforeach
                    </ul>

                    @unless ($automatic)
                        <div class="field">
                            <label for="reason">Motivo (opcional)</label>
                            <textarea id="reason" class="input" rows="2" maxlength="250" wire:model="reason"></textarea>
                        </div>
                    @endunless

                    <button type="button" class="btn block" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        {{ $automatic ? 'Cambiar a este horario' : (count($chosen) === 1 ? 'Enviar mi pedido' : 'Enviar mis '.count($chosen).' opciones') }}
                    </button>
                </div>
            @endif
        @endif
    </section>
</div>
