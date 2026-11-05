{{-- Cada grupo se abre y se cierra con su número a la vista; el primero con personas va abierto.
     Solo se pinta el número de lo que no puede esperar: avisos, solicitudes y cuotas vencidas. --}}
@php($firstWithRows = collect($sections)->search(fn (array $section) => $section['rows'] !== []))
@php($urgent = ['messages', 'requests', 'installments'])
<x-filament-widgets::widget>
    <x-filament::section heading="Por hacer hoy" icon="heroicon-o-clipboard-document-check" description="Avisos, respuestas y a quién escribirle hoy.">
        @forelse ($sections as $i => $section)
            @if ($section['rows'] === [])
                <a href="{{ $section['url'] }}" class="dash-task" aria-labelledby="follow-up-{{ $section['key'] }}">
                    <span>
                        <strong id="follow-up-{{ $section['key'] }}">{{ $section['title'] }}</strong>
                        <small>{{ $section['summary'] ?? $section['hint'] }}</small>
                    </span>
                    <span @class(['dash-count', 'is-warning' => in_array($section['key'], $urgent, true)])>{{ $section['count'] }}</span>
                </a>
            @else
                <details class="dash-group" @if ($i === $firstWithRows) open @endif>
                    <summary class="dash-task">
                        <span>
                            <strong id="follow-up-{{ $section['key'] }}">{{ $section['title'] }}</strong>
                            <small>{{ $section['summary'] ?? $section['hint'] }}</small>
                        </span>
                        <span @class(['dash-count', 'is-warning' => in_array($section['key'], $urgent, true)])>{{ $section['count'] }}</span>
                    </summary>

                    <ul class="dash-rows">
                        @foreach ($section['rows'] as $row)
                            <li>
                                <div class="dash-row-text">
                                    <a href="{{ $row['url'] }}" class="dash-name">{{ $row['name'] }}</a>
                                    <small>{{ $row['detail'] }}</small>
                                    @if ($row['contacted'])
                                        <small class="dash-done">
                                            <x-filament::icon icon="heroicon-m-check" class="dash-done-icon" />
                                            {{ $row['contacted'] }}
                                        </small>
                                    @endif
                                </div>
                                <div class="dash-row-actions">
                                    @if ($row['can_message'] && $section['template'])
                                        {{ ($this->whatsappAction)(['template' => $section['template'], 'patient' => $row['patient_id']]) }}
                                    @endif
                                    @if ($row['new_appointment_url'])
                                        <x-filament::link :href="$row['new_appointment_url']" icon="heroicon-m-calendar-days" size="sm" color="gray">Nueva cita</x-filament::link>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($section['url'])
                        <x-filament::link :href="$section['url']" size="sm" class="dash-group-more">
                            {{ $section['count'] > count($section['rows']) ? 'Ver los '.$section['count'] : 'Ver lista' }}
                        </x-filament::link>
                    @endif
                </details>
            @endif
        @empty
            <div class="dash-empty">
                <x-filament::icon icon="heroicon-o-check-circle" class="dash-empty-icon" />
                <p>Todo al día: no hay avisos, presupuestos, planes ni cuotas por perseguir.</p>
            </div>
        @endforelse
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
