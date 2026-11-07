{{--
    El día en lista (sobre todo para el celular y la tablet de recepción): tarjetas grandes en orden de hora con el
    botón del paso que sigue (Confirmar, Llegó, Atender, Cobrar…), la línea de ahora y los huecos libres con "Agendar".
--}}
<div class="agenda-list" x-data="agendaList">
    <div class="agenda-list-inner" wire:key="list-{{ $date }}" x-init="arrive()">
        @if (count($list['week']) > 1)
            <nav class="agenda-days" aria-label="Días de la semana">
                @foreach ($list['week'] as $day)
                    <button
                        type="button"
                        wire:click="$set('date', @js($day['date']))"
                        @class(['agenda-day', 'is-selected' => $day['isSelected'], 'is-today' => $day['isToday'], 'is-past' => $day['isPast']])
                        aria-label="{{ $day['label'] }}"
                        @if ($day['isSelected']) aria-current="date" @endif
                    >
                        <span class="agenda-day-letter">{{ $day['letter'] }}</span>
                        <span class="agenda-day-num">{{ $day['number'] }}</span>
                        <span class="agenda-load is-{{ $day['level'] }}" style="--load: {{ $day['load'] ?? 0 }}%" aria-hidden="true"></span>
                    </button>
                @endforeach
            </nav>
        @endif

        <p class="agenda-list-summary">{{ $list['summary'] }}</p>

        <ol class="agenda-list-items">
            @forelse ($list['items'] as $item)
                @switch($item['type'])
                    @case('appointment')
                        <li wire:key="list-appt-{{ $item['id'] }}" @class(['agenda-item', 's-'.$item['status'], 'is-unclosed' => $item['unclosed']])>
                            <div class="agenda-item-time">
                                <b>{{ $item['startTime'] }}</b>
                                <small>{{ $item['length'] }}</small>
                            </div>
                            <div class="agenda-item-body">
                                <div class="agenda-item-title">
                                    <a href="{{ $item['url'] }}" class="agenda-item-name" x-on:click="open($event, {{ $item['id'] }})">{{ $item['patient'] }}</a>
                                    @if ($item['unclosed'])<span class="tag tag-unclosed">Sin cerrar</span>@endif
                                    @if ($item['flags']['alert'])<span class="tag tag-alert" title="{{ $item['flags']['alert'] }}">Alerta médica</span>@endif
                                    @if ($item['flags']['reschedule'])<span class="tag tag-change">Pidió cambio</span>@endif
                                    @if ($item['flags']['noShows'])<span class="tag tag-missed">{{ $item['flags']['noShows'] }}</span>@endif
                                    @if ($item['isNew'])<span class="tag tag-new">Nuevo</span>@endif
                                    @if ($item['owes'])<span class="tag tag-owes">Debe {{ $item['owes'] }}</span>@endif
                                    @if ($item['hasNote'])<span class="tag tag-note">Nota</span>@endif
                                </div>
                                <p class="agenda-item-meta">
                                    {{ $item['treatment'] }}{{ $item['flags']['session'] ? ' · '.mb_strtolower($item['flags']['session']) : '' }}
                                    @if ($item['doctor'])
                                        · <span class="agenda-item-doc" style="--doc: {{ $item['doctor']['color'] }}">{{ $item['doctor']['name'] }}</span>
                                    @endif
                                </p>
                                <div class="agenda-item-foot">
                                    <span class="agenda-pill"><i aria-hidden="true"></i>{{ $item['statusLabel'] }}</span>
                                    <div class="agenda-item-actions">
                                        @if ($item['needsCall'] && $item['phoneLink'])
                                            <a href="{{ $item['phoneLink'] }}" class="agenda-call">
                                                <x-filament::icon icon="heroicon-m-phone" class="agenda-call-icon" />
                                                Llamar
                                            </a>
                                        @endif
                                        @if ($next = $this->nextStep($item['id'], $item['status']))
                                            {{ $next->size('sm') }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </li>
                        @break

                    @case('now')
                        <li class="agenda-list-now" wire:key="list-now"><span>Ahora · {{ $item['time'] }}</span></li>
                        @break

                    @case('gap')
                        <li class="agenda-gap" wire:key="list-gap-{{ $item['doctorId'] }}-{{ $item['start'] }}">
                            <div class="agenda-item-time">
                                <b>{{ $item['start'] }}</b>
                                <small>{{ $item['length'] }}</small>
                            </div>
                            <div class="agenda-item-body">
                                <span class="agenda-gap-title">Libre {{ $item['time'] }}</span>
                                @if ($item['doctor'])
                                    <span class="agenda-item-meta"><span class="agenda-item-doc" style="--doc: {{ $item['doctor']['color'] }}">{{ $item['doctor']['name'] }}</span></span>
                                @endif
                            </div>
                            @if ($list['canCreate'])
                                <button type="button" class="agenda-gap-btn" wire:click="mountAction('createAppointment', @js(['doctor' => $item['doctorId'], 'date' => $date, 'time' => $item['start']]))">Agendar</button>
                            @endif
                        </li>
                        @break

                    @case('block')
                        <li class="agenda-list-block">
                            <span class="agenda-list-block-time">{{ $item['time'] }}</span>
                            <span>{{ $item['label'] }}@if ($item['doctor']) · {{ $item['doctor']['name'] }}@endif</span>
                        </li>
                        @break
                @endswitch
            @empty
                <li class="agenda-list-empty">{{ $list['empty'] }}</li>
            @endforelse
        </ol>
    </div>
</div>
