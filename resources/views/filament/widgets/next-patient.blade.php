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
                    @if ($next['consentMissing'])
                        <li class="is-warning"><x-filament::icon icon="heroicon-m-pencil-square" /> Falta firmar el consentimiento: se firma al empezar la atención.</li>
                    @endif
                    @if (! $next['hasBackground'])
                        <li class="is-warning"><x-filament::icon icon="heroicon-m-exclamation-circle" /> Faltan los antecedentes médicos: pregunta por alergias, enfermedades y medicamentos.</li>
                    @elseif ($next['alerts'] === [])
                        <li class="is-success"><x-filament::icon icon="heroicon-m-shield-check" /> Sin alergias ni enfermedades registradas.</li>
                    @else
                        @foreach ($next['alerts'] as $alert)
                            <li @class(['is-danger' => $alert['severe'], 'is-warning' => ! $alert['severe']])><x-filament::icon :icon="$alert['severe'] ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-information-circle'" /> {{ $alert['text'] }}</li>
                        @endforeach
                    @endif
                </ul>

                <dl class="dash-next-facts">
                    <div><dt>Última atención</dt><dd>{{ $next['last'] ?? 'Es su primera visita' }}</dd></div>
                    @if (filled($next['notes']))
                        <div><dt>Dejó una nota</dt><dd>{{ $next['notes'] }}</dd></div>
                    @endif
                    @if (filled($next['internalNotes']))
                        <div><dt>Nota interna</dt><dd>{{ $next['internalNotes'] }}</dd></div>
                    @endif
                    @if ($othersWaiting > 0)
                        <div><dt>En sala</dt><dd>{{ $othersWaiting === 1 ? '1 paciente más espera' : $othersWaiting.' pacientes más esperan' }}</dd></div>
                    @endif
                </dl>

                @if ($after)
                    <p class="dash-next-after"><span>Después</span> <time>{{ $after['time'] }}</time> {{ $after['text'] }}</p>
                @endif
            @else
                <div class="dash-empty">
                    <x-filament::icon icon="heroicon-o-check-circle" class="dash-empty-icon" />
                    <p>No tienes más pacientes por atender hoy.</p>
                </div>
            @endif

        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
