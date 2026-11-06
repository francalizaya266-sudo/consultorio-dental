{{--
    Panel al costado de la agenda (solo en pantallas anchas): el mes con lo lleno de cada día para saltar a uno,
    "Buscar hueco" para contestar "¿tienen hora para…?" con un toque, y quién de la lista de espera cabe ese día.
--}}
<aside class="agenda-rail" aria-label="Buscar horas libres">
    <section class="agenda-card">
        <div class="agenda-card-head">
            <h2 class="agenda-card-title">{{ $rail['month'] }}</h2>
            <div class="agenda-nav is-small" role="group" aria-label="Cambiar de mes">
                <button type="button" class="agenda-nav-btn is-icon" wire:click="shiftMonth(-1)" aria-label="Mes anterior" title="Mes anterior">
                    <x-filament::icon icon="heroicon-m-chevron-left" class="agenda-nav-icon" />
                </button>
                <button type="button" class="agenda-nav-btn is-icon" wire:click="shiftMonth(1)" aria-label="Mes siguiente" title="Mes siguiente">
                    <x-filament::icon icon="heroicon-m-chevron-right" class="agenda-nav-icon" />
                </button>
            </div>
        </div>

        <div class="agenda-month">
            @foreach (['L', 'M', 'X', 'J', 'V', 'S', 'D'] as $weekday)
                <span class="agenda-month-weekday" aria-hidden="true">{{ $weekday }}</span>
            @endforeach

            @foreach ($rail['days'] as $day)
                <button
                    type="button"
                    wire:click="$set('date', @js($day['date']))"
                    @class([
                        'agenda-month-day',
                        'is-other' => $day['isOtherMonth'],
                        'is-today' => $day['isToday'],
                        'is-past' => $day['isPast'],
                        'is-selected' => $day['isSelected'],
                        'is-range-start' => $day['isRangeStart'],
                        'is-range-end' => $day['isRangeEnd'],
                        'is-closed' => $day['load'] === null,
                    ])
                    aria-label="{{ $day['label'] }}"
                    title="{{ $day['label'] }}"
                    @if ($day['isSelected'] && $day['isRangeStart']) aria-current="date" @endif
                >
                    <span class="agenda-month-num">{{ $day['number'] }}</span>
                    <span class="agenda-load is-{{ $day['level'] }}" style="--load: {{ $day['load'] ?? 0 }}%" aria-hidden="true"></span>
                </button>
            @endforeach
        </div>

        <div class="agenda-load-legend" aria-hidden="true">
            <span><i class="agenda-load is-some" style="--load: 100%"></i>Con espacio</span>
            <span><i class="agenda-load is-full" style="--load: 100%"></i>Casi lleno</span>
        </div>
    </section>

    <section class="agenda-card">
        <div>
            <h2 class="agenda-card-title">Buscar hueco</h2>
            <p class="agenda-card-sub">Las próximas horas libres para el tratamiento. Toca una y la cita se abre lista.</p>
        </div>

        <div class="agenda-chips" role="group" aria-label="Tratamiento">
            @foreach ($rail['quick'] as $treatment)
                <button type="button" class="agenda-chip" aria-pressed="{{ $findTreatment === $treatment->getKey() ? 'true' : 'false' }}" wire:click="findFor({{ $treatment->getKey() }})">{{ $treatment->name }}</button>
            @endforeach
        </div>

        @if ($rail['others']->isNotEmpty())
            <x-filament::input.wrapper>
                <x-filament::input.select x-on:change="$wire.findFor($event.target.value, false)" aria-label="Otro tratamiento">
                    <option value="">Otro tratamiento…</option>
                    @foreach ($rail['others'] as $treatment)
                        <option value="{{ $treatment->getKey() }}" @selected($findTreatment === $treatment->getKey())>{{ $treatment->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif

        @if ($rail['slots'] === null)
            <p class="agenda-card-hint">Elige un tratamiento para ver cuándo entra.</p>
        @elseif ($rail['slots'] === [])
            <p class="agenda-card-hint">No hay horas libres para {{ mb_strtolower($rail['treatment']->name) }} en las próximas {{ intdiv($rail['searchDays'], 7) }} semanas.</p>
        @else
            <div class="agenda-find">
                @foreach ($rail['slots'] as $found)
                    <div class="agenda-find-day" wire:key="find-{{ $findTreatment }}-{{ $found['date'] }}">
                        <span class="agenda-find-date">{{ $found['label'] }}</span>
                        <div class="agenda-find-times">
                            @foreach ($found['slots'] as $slot)
                                @if ($rail['canCreate'])
                                    <button
                                        type="button"
                                        class="agenda-slot"
                                        wire:click="mountAction('createAppointment', @js(['doctor' => $slot['doctor'], 'date' => $found['date'], 'time' => $slot['time'], 'treatment' => $findTreatment]))"
                                        title="{{ $found['label'] }} {{ $slot['time'] }} · {{ $slot['doctorName'] }}"
                                    >
                                        {{ $slot['time'] }}@if ($slot['recommended'])<span class="agenda-slot-star" aria-label="pegado a otra cita">★</span>@endif
                                        @if ($rail['severalDoctors'])
                                            <span class="agenda-slot-doc" style="--doc: {{ $slot['color'] }}" aria-label="con {{ $slot['doctorName'] }}">{{ $slot['initials'] }}</span>
                                        @endif
                                    </button>
                                @else
                                    <span class="agenda-slot" title="{{ $slot['doctorName'] }}">{{ $slot['time'] }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if (collect($rail['slots'])->flatMap(fn (array $found) => $found['slots'])->contains('recommended', true))
                    <p class="agenda-card-hint">★ = pegada a otra cita, no deja huecos.</p>
                @endif
            </div>
        @endif
    </section>

    @if ($rail['waitlist'] !== [])
        <section class="agenda-card">
            <h2 class="agenda-card-title">De la lista de espera, caben {{ $rail['dayName'] }}</h2>

            <ul class="agenda-wait">
                @foreach ($rail['waitlist'] as $fit)
                    @php
                        $entry = $fit['entry'];
                        $offer = ($this->offerAction)(['entry' => $entry->getKey(), 'doctor' => $fit['doctor']->getKey(), 'date' => $date, 'time' => $fit['time']]);
                    @endphp
                    <li wire:key="wait-{{ $entry->getKey() }}">
                        <span class="agenda-wait-who">
                            <b>{{ $entry->patient->full_name }}</b>
                            <small>{{ $entry->treatment->name }} · {{ mb_strtolower($entry->preferred_period->getLabel()) }}</small>
                        </span>
                        @if ($offer->isVisible())
                            {{ $offer->label('Ofrecer '.$fit['time'])->icon(null)->size('sm') }}
                        @elseif ($rail['canCreate'])
                            <button
                                type="button"
                                class="agenda-slot"
                                wire:click="mountAction('createAppointment', @js(['patient' => $entry->patient_id, 'doctor' => $fit['doctor']->getKey(), 'date' => $date, 'time' => $fit['time'], 'treatment' => $entry->treatment_id]))"
                            >Agendar {{ $fit['time'] }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($rail['waitlistUrl'])
                <a href="{{ $rail['waitlistUrl'] }}" class="agenda-card-link">Ver toda la lista de espera</a>
            @endif
        </section>
    @endif
</aside>
