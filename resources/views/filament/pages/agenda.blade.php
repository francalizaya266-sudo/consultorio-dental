<x-filament-panels::page>
    @if ($weekByDefault)
        <div hidden x-data x-init="if (window.innerWidth < 640) $wire.set('mode', 'day')"></div>
    @endif

    <div class="agenda-toolbar">
        <div class="agenda-toolbar-group">
            <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" label="Anterior" wire:click="shift(-1)" />
            <x-filament::button color="gray" size="sm" wire:click="goToday">Hoy</x-filament::button>
            <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" label="Siguiente" wire:click="shift(1)" />
            <span class="agenda-heading">{{ $heading }}</span>
        </div>

        <div class="agenda-toolbar-group">
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="date" aria-label="Ir a la fecha" />
            </x-filament::input.wrapper>

            @if ($mode === 'week' && $doctors->count() > 1)
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="doctor" aria-label="Doctor">
                        @foreach ($doctors as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif

            <x-filament::tabs>
                <x-filament::tabs.item :active="$mode === 'day'" wire:click="$set('mode', 'day')">Día</x-filament::tabs.item>
                <x-filament::tabs.item :active="$mode === 'week'" wire:click="$set('mode', 'week')">Semana</x-filament::tabs.item>
            </x-filament::tabs>
        </div>
    </div>

    <div class="agenda-info">
        <div class="agenda-legend">
            <span class="s-pending">Pendiente</span>
            <span class="s-confirmed">Confirmada</span>
            <span class="s-checked_in">En sala / en atención</span>
            <span class="s-completed">Atendida</span>
            <span class="s-block">No se atiende</span>
        </div>
        {{-- La ayuda se abre cuando se necesita: el que usa la agenda todos los días ya la sabe. --}}
        <details class="agenda-help">
            <summary>Cómo usar la agenda</summary>
            <p>Toca una cita para ver sus datos y botones, o un espacio libre para agendar ahí. Arrastra una cita para cambiarla de horario (en celular, mantenla presionada y luego arrástrala).</p>
        </details>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            /**
             * Grilla de la agenda. Una cita se mueve con el mouse apenas se arrastra; con el dedo,
             * hay que mantenerla presionada un momento para no confundirlo con desplazar la pantalla.
             */
            Alpine.data('agendaGrid', () => ({
                press: null,
                dragging: null,
                over: null,
                refused: null,
                pointer: null,
                droppedAt: 0,
                preview: { top: 0, height: 0, time: '' },
                refresher: null,

                // Se refresca sola cada 30 s, salvo mientras alguien arrastra una cita o tiene una ventana abierta.
                init() {
                    this.refresher = setInterval(() => {
                        if (document.hidden || this.press || this.dragging || this.$wire.mountedActions?.length) return;
                        this.$wire.$refresh();
                    }, 30000);
                },

                destroy() {
                    clearInterval(this.refresher);
                },

                // Se leen del HTML porque cambian al pasar de día o de vista.
                get from() {
                    return Number(this.$root.dataset.from);
                },

                get scale() {
                    return Number(this.$root.dataset.scale);
                },

                minuteAt(clientY, column, offset = 0) {
                    const minutes = this.from + (clientY - column.getBoundingClientRect().top - offset) / this.scale;

                    return Math.max(0, Math.min(24 * 60 - 5, Math.round(minutes / 5) * 5));
                },

                label(minutes) {
                    return String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
                },

                create(event, column, doctor, date) {
                    if (event.target.closest('.agenda-appt') || this.justDropped()) return;
                    this.$wire.mountAction('createAppointment', { doctor, date, time: this.label(this.minuteAt(event.clientY, column)) });
                },

                /** Abre el panel de la cita. Con Ctrl o ⌘ se abre la ficha completa en otra pestaña. */
                open(event, id) {
                    if (this.justDropped()) {
                        event.preventDefault();
                        return;
                    }
                    if (event.ctrlKey || event.metaKey || event.shiftKey) return;
                    event.preventDefault();
                    this.$wire.mountAction('appointment', { id });
                },

                start(event, card) {
                    if (event.pointerType === 'mouse' && event.button !== 0) return;
                    const rect = card.getBoundingClientRect();
                    this.cancel();
                    this.press = {
                        id: Number(card.dataset.id),
                        key: card.closest('.agenda-col').dataset.key,
                        doctor: card.closest('.agenda-col').dataset.doctor,
                        treatment: card.dataset.treatment,
                        time: card.dataset.start,
                        x: event.clientX,
                        y: event.clientY,
                        offset: event.clientY - rect.top,
                        height: rect.height,
                        hold: event.pointerType === 'mouse' ? null : setTimeout(() => this.begin(), 350),
                    };
                    this.pointer = { clientX: event.clientX, clientY: event.clientY };
                },

                begin() {
                    if (! this.press) return;
                    this.dragging = this.press;
                    navigator.vibrate?.(15);
                    this.track(this.pointer);
                    this.autoScroll();
                },

                move(event) {
                    if (! this.press) return;
                    this.pointer = { clientX: event.clientX, clientY: event.clientY };

                    if (! this.dragging) {
                        const moved = Math.hypot(event.clientX - this.press.x, event.clientY - this.press.y);
                        if (this.press.hold === null && moved > 6) this.begin();
                        else if (this.press.hold !== null && moved > 10) this.cancel();
                        return;
                    }

                    this.track(this.pointer);
                },

                track(point) {
                    let column = document.elementFromPoint(point.clientX, point.clientY)?.closest('.agenda-col');
                    column = column && this.$root.contains(column) ? column : null;
                    // Otro doctor solo si realiza el mismo tratamiento.
                    const allowed = column && (column.dataset.doctor === this.dragging.doctor || column.dataset.treatments.split(',').includes(this.dragging.treatment));
                    this.over = allowed ? column.dataset.key : null;
                    this.refused = column && ! allowed ? column.dataset.key : null;
                    if (! column) return;
                    const minutes = this.minuteAt(point.clientY, column, this.dragging.offset);
                    this.preview = { top: (minutes - this.from) * this.scale, height: this.dragging.height, time: this.label(minutes) };
                },

                autoScroll() {
                    if (! this.dragging) return;
                    const frame = this.$root.getBoundingClientRect();
                    const { clientX: x, clientY: y } = this.pointer;
                    const dy = y > Math.min(frame.bottom, window.innerHeight) - 40 ? 10 : (y < frame.top + 90 ? -10 : 0);
                    const dx = x > frame.right - 40 ? 10 : (x < frame.left + 90 ? -10 : 0);
                    if (dx || dy) {
                        this.$root.scrollBy(dx, dy);
                        this.track(this.pointer);
                    }
                    requestAnimationFrame(() => this.autoScroll());
                },

                end() {
                    const drag = this.dragging;
                    const target = this.over;
                    const time = this.preview.time;
                    this.cancel();
                    if (! drag) return;
                    this.droppedAt = Date.now();
                    if (! target || (target === drag.key && time === drag.time)) return;
                    this.$wire.mountAction('move', { id: drag.id, column: target, time: time });
                },

                cancel() {
                    clearTimeout(this.press?.hold);
                    this.press = null;
                    this.dragging = null;
                    this.over = null;
                    this.refused = null;
                },

                /** El clic que sigue a soltar una cita no debe abrirla ni crear otra. */
                justDropped() {
                    return Date.now() - this.droppedAt < 500;
                },
            }));
        });
    </script>

    @if (count($columns) === 0)
        <div class="agenda-frame agenda-empty">No hay doctores activos para mostrar.</div>
    @else
        @php
            $height = ($to - $from) * $scale;
            $nowMinute = now()->hour * 60 + now()->minute;
        @endphp

        <div
            class="agenda-frame"
            :class="{ 'is-dragging': dragging }"
            data-from="{{ $from }}"
            data-scale="{{ $scale }}"
            x-data="agendaGrid"
            x-on:pointermove.window="move($event)"
            x-on:pointerup.window="end()"
            x-on:pointercancel.window="cancel()"
            x-on:keydown.escape.window="cancel()"
            x-on:touchmove="if (dragging) $event.preventDefault()"
            x-on:contextmenu="if (press || dragging) $event.preventDefault()"
        >
            <div class="agenda-grid" style="grid-template-columns: 3.25rem repeat({{ count($columns) }}, minmax({{ $mode === 'week' ? '7rem' : '9.5rem' }}, 1fr)); --hour: {{ 60 * $scale }}px;">
                <div class="agenda-head agenda-corner"></div>

                @foreach ($columns as $column)
                    <div @class(['agenda-head', 'is-today' => $column['isToday'] && $mode === 'week'])>
                        <div class="agenda-head-title"><i style="background: {{ $column['color'] }}"></i>{{ $column['title'] }}</div>
                        @if ($column['subtitle'])
                            <div class="agenda-head-sub">{{ $column['subtitle'] }}</div>
                        @endif
                    </div>
                @endforeach

                <div class="agenda-times" style="height: {{ $height }}px">
                    @foreach ($hours as $hour)
                        <span @class(['is-first' => $hour * 60 === $from]) style="top: {{ ($hour * 60 - $from) * $scale }}px">{{ sprintf('%02d:00', $hour) }}</span>
                    @endforeach
                </div>

                @foreach ($columns as $column)
                    <div
                        wire:key="col-{{ $column['key'] }}"
                        class="agenda-col"
                        data-key="{{ $column['key'] }}"
                        data-doctor="{{ $column['doctorId'] }}"
                        data-treatments="{{ implode(',', $column['treatments']) }}"
                        :class="{ 'is-over': over === @js($column['key']), 'is-refused': refused === @js($column['key']) }"
                        style="height: {{ $height }}px"
                        x-on:click="create($event, $el, {{ $column['doctorId'] }}, @js($column['date']))"
                    >
                        <div
                            class="agenda-drop"
                            x-cloak
                            x-show="over === @js($column['key']) || refused === @js($column['key'])"
                            :class="{ 'is-refused': refused === @js($column['key']) }"
                            :style="{ top: preview.top + 'px', height: preview.height + 'px' }"
                        ><span x-text="refused === @js($column['key']) ? 'No realiza este tratamiento' : preview.time"></span></div>

                        @foreach ($column['working'] as $range)
                            <div class="agenda-work" style="top: {{ ($range['start'] - $from) * $scale }}px; height: {{ ($range['end'] - $range['start']) * $scale }}px"></div>
                        @endforeach

                        @foreach ($column['blocks'] as $block)
                            <div class="agenda-block" style="top: {{ (max($block['start'], $from) - $from) * $scale }}px; height: {{ (min($block['end'], $to) - max($block['start'], $from)) * $scale }}px">{{ $block['label'] }}</div>
                        @endforeach

                        @if ($column['date'] === today()->toDateString() && $nowMinute > $from && $nowMinute < $to)
                            <div class="agenda-now" style="top: {{ ($nowMinute - $from) * $scale }}px"></div>
                        @endif

                        @foreach ($column['appointments'] as $appt)
                            <div class="agenda-buffer" style="top: {{ ($appt['end'] - $from) * $scale }}px; height: {{ ($appt['until'] - $appt['end']) * $scale }}px"></div>
                            <a
                                wire:key="appt-{{ $appt['id'] }}"
                                href="{{ $appt['url'] }}"
                                @class(['agenda-appt', 's-'.$appt['status'], 'is-movable' => $appt['draggable'], 'is-short' => $appt['end'] - $appt['start'] <= 30])
                                style="top: {{ ($appt['start'] - $from) * $scale }}px; height: {{ max(($appt['end'] - $appt['start']) * $scale - 2, 18) }}px; border-left-color: {{ $column['color'] }}"
                                title="{{ $appt['time'] }} · {{ $appt['patient'] }} · {{ $appt['treatment'] }} · {{ $appt['statusLabel'] }}{{ $appt['phone'] ? ' · '.$appt['phone'] : '' }}"
                                draggable="false"
                                x-on:click="open($event, {{ $appt['id'] }})"
                                @if ($appt['draggable'])
                                    data-id="{{ $appt['id'] }}"
                                    data-treatment="{{ $appt['treatmentId'] }}"
                                    data-start="{{ sprintf('%02d:%02d', intdiv($appt['start'], 60), $appt['start'] % 60) }}"
                                    :class="{ 'is-lifted': dragging && dragging.id === {{ $appt['id'] }} }"
                                    x-on:pointerdown="start($event, $el)"
                                @endif
                            >
                                <b>{{ $appt['patient'] }}@if ($appt['needsCall'])<span class="tag tag-call">Llamar</span>@endif @if ($appt['isNew'])<span class="tag tag-new">Nuevo</span>@endif @if ($appt['owes'])<span class="tag tag-owes">Debe {{ $appt['owes'] }}</span>@endif @if ($appt['hasNote'])<span class="tag tag-note">Nota</span>@endif</b>
                                <small>{{ $appt['time'] }} · {{ $appt['treatment'] }}</small>
                                <small>{{ $appt['statusLabel'] }}@if ($appt['phone']) · {{ $appt['phone'] }}@endif</small>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
