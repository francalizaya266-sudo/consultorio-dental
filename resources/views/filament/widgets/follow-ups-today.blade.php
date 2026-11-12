{{-- Arriba, los pendientes que recepción anota a mano; lo hecho hoy queda tachado para el turno que sigue.
     Cada grupo se abre y se cierra con su número a la vista; el primero con personas va abierto.
     Solo se pinta el número de lo que no puede esperar: citas sin cerrar, avisos, solicitudes, cuotas vencidas, el laboratorio y el inventario. --}}
@php($firstWithRows = collect($sections)->search(fn (array $section) => $section['rows'] !== []))
@php($urgent = ['left-open', 'messages', 'requests', 'installments', 'lab', 'supplies', 'batches'])
<x-filament-widgets::widget>
    <x-filament::section heading="Por hacer hoy" icon="heroicon-o-clipboard-document-check" description="Avisos, respuestas y a quién escribirle hoy.">
        <x-slot name="afterHeader">
            {{ $this->addNoteAction }}
        </x-slot>

        @if ($notes !== [])
            <ul class="dash-rows dash-notes" aria-label="Pendientes anotados">
                @foreach ($notes as $note)
                    <li @class(['is-done' => $note['done']])>
                        <div class="dash-row-text">
                            <span class="dash-name">{{ $note['body'] }}</span>
                            <small @class(['dash-done' => $note['done'], 'dash-late' => $note['late']])>
                                @if ($note['done'])
                                    <x-filament::icon icon="heroicon-m-check" class="dash-done-icon" />
                                @endif
                                {{ $note['status'] }}
                            </small>
                            @if ($note['patient'])
                                <small><a href="{{ $note['patient_url'] }}" class="dash-note-patient">{{ $note['patient'] }}</a></small>
                            @endif
                        </div>
                        <div class="dash-row-actions">
                            {{ $note['done'] ? ($this->noteUndoAction)(['note' => $note['id']]) : ($this->noteDoneAction)(['note' => $note['id']]) }}
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

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
            <div @class(['dash-empty', 'has-notes' => $notes !== []])>
                <x-filament::icon icon="heroicon-o-check-circle" class="dash-empty-icon" />
                <p>Todo al día: no hay avisos, presupuestos, planes ni cuotas por perseguir.</p>
            </div>
        @endforelse
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
