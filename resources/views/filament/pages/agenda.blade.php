<x-filament-panels::page>
    <style>
        .agenda-toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; }
        .agenda-toolbar-group { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .agenda-heading { font-size: 1.05rem; font-weight: 600; color: var(--gray-950); }
        .dark .agenda-heading { color: #fff; }
        .agenda-info { display: flex; flex-wrap: wrap; gap: .4rem 1.5rem; align-items: center; justify-content: space-between; }
        .agenda-help { font-size: .8rem; color: var(--gray-500); }
        .agenda-frame { overflow: auto; max-height: calc(100vh - 15rem); border: 1px solid var(--gray-200); border-radius: .75rem; background: #fff; }
        .dark .agenda-frame { border-color: rgb(255 255 255 / .1); background: var(--gray-900); }
        .agenda-grid { display: grid; min-width: 100%; position: relative; }
        .agenda-head { position: sticky; top: 0; z-index: 3; background: inherit; border-bottom: 1px solid var(--gray-200); padding: .5rem .5rem .4rem; min-height: 3.25rem; background: #fff; }
        .dark .agenda-head { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .agenda-head-title { font-weight: 600; font-size: .85rem; display: flex; align-items: center; gap: .4rem; color: var(--gray-950); }
        .dark .agenda-head-title { color: #fff; }
        .agenda-head-title i { width: .6rem; height: .6rem; border-radius: 9999px; display: inline-block; flex: none; }
        .agenda-head-sub { font-size: .72rem; color: var(--gray-500); }
        .agenda-head.is-today .agenda-head-title { color: var(--primary-600); }
        .agenda-corner { position: sticky; left: 0; top: 0; z-index: 4; }
        .agenda-times { position: sticky; left: 0; z-index: 2; background: #fff; border-right: 1px solid var(--gray-200); }
        .dark .agenda-times { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .agenda-times span { position: absolute; right: .4rem; transform: translateY(-50%); font-size: .7rem; color: var(--gray-500); }
        .agenda-times span.is-first { transform: translateY(.15rem); }
        .agenda-col { position: relative; border-right: 1px solid var(--gray-100); background: repeating-linear-gradient(to bottom, transparent 0, transparent calc(var(--hour) - 1px), var(--gray-100) calc(var(--hour) - 1px), var(--gray-100) var(--hour)), var(--gray-50); cursor: copy; }
        .dark .agenda-col { border-color: rgb(255 255 255 / .05); background: repeating-linear-gradient(to bottom, transparent 0, transparent calc(var(--hour) - 1px), rgb(255 255 255 / .05) calc(var(--hour) - 1px), rgb(255 255 255 / .05) var(--hour)), var(--gray-950); }
        .agenda-col.is-over { outline: 2px dashed var(--primary-500); outline-offset: -2px; }
        .agenda-col.is-refused { outline: 2px dashed var(--danger-400); outline-offset: -2px; }
        .agenda-frame.is-dragging, .agenda-frame.is-dragging * { cursor: grabbing !important; user-select: none; }
        .agenda-drop { position: absolute; left: 4px; right: 4px; z-index: 3; pointer-events: none; border: 2px dashed var(--primary-500); border-radius: .45rem; background: color-mix(in oklab, var(--primary-500) 12%, transparent); }
        .agenda-drop.is-refused { border-color: var(--danger-500); background: rgb(239 68 68 / .08); }
        .agenda-drop.is-refused span { background: var(--danger-600); }
        .agenda-drop span { position: absolute; top: -.1rem; left: -.1rem; transform: translateY(-100%); background: var(--primary-600); color: #fff; font-size: .7rem; font-weight: 600; border-radius: .3rem; padding: .05rem .35rem; white-space: nowrap; }
        .agenda-work { position: absolute; left: 0; right: 0; background: #fff; pointer-events: none; }
        .dark .agenda-work { background: var(--gray-900); }
        .agenda-block { position: absolute; left: 0; right: 0; pointer-events: none; background: repeating-linear-gradient(135deg, rgb(148 163 184 / .25) 0 6px, transparent 6px 12px); border-top: 1px solid var(--gray-300); border-bottom: 1px solid var(--gray-300); font-size: .7rem; color: var(--gray-600); padding: .2rem .4rem; overflow: hidden; }
        .agenda-now { position: absolute; left: 0; right: 0; height: 2px; background: var(--danger-500); z-index: 1; pointer-events: none; }
        .agenda-appt { position: absolute; left: 4px; right: 4px; border-radius: .45rem; border: 1px solid; border-left-width: 4px; padding: .2rem .4rem; font-size: .72rem; line-height: 1.15; overflow: hidden; color: var(--gray-900); text-decoration: none; display: block; cursor: pointer; box-shadow: 0 1px 2px rgb(0 0 0 / .06); }
        .agenda-appt.is-movable { cursor: grab; touch-action: pan-x pan-y; -webkit-touch-callout: none; user-select: none; -webkit-user-select: none; }
        .agenda-appt.is-lifted { opacity: .35; }
        .agenda-appt:hover { box-shadow: 0 2px 8px rgb(0 0 0 / .15); z-index: 2; }
        .agenda-appt b { display: block; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .agenda-appt small { display: block; color: var(--gray-600); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .agenda-appt .tag { display: inline-block; font-size: .62rem; font-weight: 600; border-radius: .25rem; padding: 0 .25rem; margin-left: .2rem; vertical-align: 1px; }
        .agenda-appt .tag-call { background: var(--danger-100); color: var(--danger-700); }
        .agenda-appt .tag-new { background: var(--info-100); color: var(--info-700); }
        .agenda-appt .tag-owes { background: var(--warning-100); color: var(--warning-800); }
        .agenda-appt .tag-note { background: var(--gray-200); color: var(--gray-700); }
        .agenda-buffer { position: absolute; left: 4px; right: 4px; pointer-events: none; background: repeating-linear-gradient(135deg, rgb(148 163 184 / .2) 0 3px, transparent 3px 6px); border-radius: 0 0 .35rem .35rem; }
        .s-pending { background: #fffbeb; border-color: #fcd34d; }
        .s-confirmed { background: #ecfdf5; border-color: #6ee7b7; }
        .s-checked_in { background: #eff6ff; border-color: #93c5fd; }
        .s-in_progress { background: #eef2ff; border-color: #a5b4fc; }
        .s-completed { background: #f4f4f5; border-color: #d4d4d8; color: var(--gray-500); }
        .s-no_show { background: #fff1f2; border-color: #fda4af; color: var(--gray-500); text-decoration: line-through; }
        .agenda-legend { display: flex; flex-wrap: wrap; gap: .4rem .9rem; font-size: .75rem; color: var(--gray-600); }
        .agenda-legend span::before { content: ""; display: inline-block; width: .7rem; height: .7rem; border-radius: .2rem; border: 1px solid; margin-right: .3rem; vertical-align: -1px; }
        .agenda-legend .s-pending::before { background: #fffbeb; border-color: #fcd34d; }
        .agenda-legend .s-confirmed::before { background: #ecfdf5; border-color: #6ee7b7; }
        .agenda-legend .s-checked_in::before { background: #eff6ff; border-color: #93c5fd; }
        .agenda-legend .s-completed::before { background: #f4f4f5; border-color: #d4d4d8; }
        .agenda-legend .s-block::before { background: repeating-linear-gradient(135deg, rgb(148 163 184 / .5) 0 2px, transparent 2px 4px); border-color: var(--gray-300); }
        .agenda-empty { padding: 3rem 1rem; text-align: center; color: var(--gray-500); }
        /* Modo oscuro: el mismo color de cada estado, como tinte sobre el fondo oscuro. */
        .dark .agenda-appt { color: var(--gray-100); box-shadow: none; }
        .dark .agenda-appt small { color: var(--gray-400); }
        .dark .agenda-appt .tag-call { background: rgb(239 68 68 / .2); color: var(--danger-300); }
        .dark .agenda-appt .tag-new { background: rgb(59 130 246 / .2); color: var(--info-300); }
        .dark .agenda-appt .tag-owes { background: rgb(245 158 11 / .2); color: var(--warning-300); }
        .dark .agenda-appt .tag-note { background: rgb(255 255 255 / .12); color: var(--gray-300); }
        .dark .agenda-block { border-color: rgb(255 255 255 / .1); color: var(--gray-400); }
        .dark .agenda-buffer { background: repeating-linear-gradient(135deg, rgb(148 163 184 / .12) 0 3px, transparent 3px 6px); }
        .dark .agenda-legend { color: var(--gray-400); }
        .dark .s-pending, .dark .agenda-legend .s-pending::before { background: rgb(245 158 11 / .14); border-color: rgb(251 191 36 / .5); }
        .dark .s-confirmed, .dark .agenda-legend .s-confirmed::before { background: rgb(16 185 129 / .14); border-color: rgb(52 211 153 / .5); }
        .dark .s-checked_in, .dark .agenda-legend .s-checked_in::before { background: rgb(59 130 246 / .16); border-color: rgb(96 165 250 / .55); }
        .dark .s-in_progress { background: rgb(99 102 241 / .16); border-color: rgb(129 140 248 / .55); }
        .dark .s-completed, .dark .agenda-legend .s-completed::before { background: rgb(255 255 255 / .05); border-color: rgb(255 255 255 / .15); }
        .dark .s-completed, .dark .s-no_show { color: var(--gray-400); }
        .dark .s-no_show { background: rgb(244 63 94 / .1); border-color: rgb(251 113 133 / .45); }
        .dark .agenda-legend .s-block::before { border-color: rgb(255 255 255 / .2); }
    </style>

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
        <p class="agenda-help">Toca una cita para ver sus datos y botones, o un espacio libre para agendar ahí. Arrastra una cita para cambiarla de horario (en celular, mantenla presionada y luego arrástrala).</p>
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
                                @class(['agenda-appt', 's-'.$appt['status'], 'is-movable' => $appt['draggable']])
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
