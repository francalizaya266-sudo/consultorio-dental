<x-filament-widgets::widget>
    <div class="dash-doctor" wire:poll.30s>
        <x-filament::section heading="Tu próximo paciente" icon="heroicon-o-user">
            @if ($next)
                <div class="dash-next">
                    <div class="dash-next-main">
                        <p class="dash-next-name"><time>{{ $next['time'] }}</time> {{ $next['name'] }}@if ($next['age'])<span> · {{ $next['age'] }}</span>@endif</p>
                        <p class="dash-next-reason">{{ $next['reason'] }}</p>
                        <x-filament::badge :color="$next['stateColor']" icon="heroicon-m-clock">{{ $next['state'] }}</x-filament::badge>
                    </div>
                    <div class="dash-next-actions">
                        {{ ($this->startAction)(['appointment' => $next['id']]) }}
                        <x-filament::button :href="$next['historyUrl']" tag="a" color="gray" icon="heroicon-o-document-text">Ver historia</x-filament::button>
                    </div>
                </div>

                <ul class="dash-alerts" aria-label="Antes de atender">
                    @if (! $next['hasBackground'])
                        <li class="is-warning"><x-filament::icon icon="heroicon-m-exclamation-circle" /> Faltan los antecedentes médicos: pregunta por alergias, enfermedades y medicamentos.</li>
                    @elseif ($next['alerts'] === [])
                        <li class="is-success"><x-filament::icon icon="heroicon-m-shield-check" /> Sin alergias ni enfermedades registradas.</li>
                    @else
                        @foreach ($next['alerts'] as $alert)
                            <li class="is-danger"><x-filament::icon icon="heroicon-m-exclamation-triangle" /> {{ $alert }}</li>
                        @endforeach
                    @endif
                </ul>

                <dl class="dash-next-facts">
                    <div><dt>Última atención</dt><dd>{{ $next['last'] ?? 'Es su primera visita' }}</dd></div>
                    @if (filled($next['notes']))
                        <div><dt>Dejó una nota</dt><dd>{{ $next['notes'] }}</dd></div>
                    @endif
                    @if ($othersWaiting > 0)
                        <div><dt>En sala</dt><dd>{{ $othersWaiting === 1 ? '1 paciente más espera' : $othersWaiting.' pacientes más esperan' }}</dd></div>
                    @endif
                </dl>
            @else
                <div class="dash-empty">
                    <x-filament::icon icon="heroicon-o-check-circle" class="dash-empty-icon" />
                    <p>No tienes más pacientes por atender hoy.</p>
                </div>
            @endif

            @if ($open !== [])
                <section class="dash-group" aria-labelledby="open-consultations">
                    <header class="dash-group-head">
                        <h3 id="open-consultations">Atenciones sin terminar <span class="dash-count is-warning">{{ count($open) }}</span></h3>
                    </header>
                    <ul class="dash-rows">
                        @foreach ($open as $consultation)
                            <li>
                                <div class="dash-row-text">
                                    <span class="dash-name">{{ $consultation['name'] }}</span>
                                    <small>{{ $consultation['detail'] }}</small>
                                </div>
                                <div class="dash-row-actions">
                                    <x-filament::link :href="$consultation['url']" icon="heroicon-m-pencil-square" size="sm">Continuar</x-filament::link>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
