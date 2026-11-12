<x-filament-panels::page>
    {{-- Sin una vista pedida, en el celular se abre la lista del día: la grilla no entra bien en la pantalla. --}}
    @if ($modeByDefault && $mode !== 'list')
        <div hidden x-data x-init="if (window.innerWidth < 640) $wire.set('mode', 'list')"></div>
    @endif

    @php
        $legend = [
            's-pending' => 'Pendiente',
            's-confirmed' => 'Confirmada',
            's-checked_in' => 'En sala',
            's-in_progress' => 'En atención',
            's-completed' => 'Atendida',
            's-no_show' => 'No asistió',
            's-block' => 'No se atiende',
            // En una columna angosta (la semana) las etiquetas no entran: lo que pide algo se ve como un punto.
            's-dot' => 'Punto rojo: sin cerrar, llamar o en bloqueo',
        ];
    @endphp

    {{-- En la computadora, al lado de la agenda va el panel para buscar horas libres; ahí se puede esconder (la semana gana ancho) y cada computadora recuerda cómo lo dejaron. --}}
    {{-- En el celular y la tablet no entra al costado: empieza cerrado y "Buscar hueco" lo abre debajo de la barra. --}}
    <div
        class="agenda-layout"
        x-data="{ railHidden: (() => { try { return localStorage.getItem('agenda-rail') === 'hidden' } catch (e) { return false } })(), railOpen: false }"
        :class="{ 'is-rail-hidden': railHidden, 'is-rail-open': railOpen }"
    >
    <div class="agenda-main">
    {{-- La barra y los colores van juntos, pegados a la grilla: en el celular la agenda empieza más arriba. --}}
    <div class="agenda-controls">
        <div class="agenda-toolbar">
            <div class="agenda-toolbar-group">
                {{-- Atrás, hoy y adelante en un solo control: se usan juntos todo el día. --}}
                <div class="agenda-nav" role="group" aria-label="{{ $mode === 'week' ? 'Cambiar de semana' : 'Cambiar de día' }}">
                    <button type="button" class="agenda-nav-btn is-icon" wire:click="shift(-1)" aria-label="{{ $mode === 'week' ? 'Semana anterior' : 'Día anterior' }}" title="{{ $mode === 'week' ? 'Semana anterior' : 'Día anterior' }}">
                        <x-filament::icon icon="heroicon-m-chevron-left" class="agenda-nav-icon" />
                    </button>
                    <button type="button" class="agenda-nav-btn" wire:click="goToday">Hoy</button>
                    <button type="button" class="agenda-nav-btn is-icon" wire:click="shift(1)" aria-label="{{ $mode === 'week' ? 'Semana siguiente' : 'Día siguiente' }}" title="{{ $mode === 'week' ? 'Semana siguiente' : 'Día siguiente' }}">
                        <x-filament::icon icon="heroicon-m-chevron-right" class="agenda-nav-icon" />
                    </button>
                </div>
                <x-filament::input.wrapper class="agenda-date">
                    <x-filament::input type="date" wire:model.live="date" aria-label="Ir a la fecha" />
                </x-filament::input.wrapper>
            </div>

            <div class="agenda-toolbar-group">
                @if ($mode === 'week' && $doctors->count() > 1)
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="doctor" aria-label="Doctor">
                            @foreach ($doctors as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif

                <x-filament::tabs class="agenda-modes" label="Vista">
                    <x-filament::tabs.item :active="$mode === 'day'" wire:click="$set('mode', 'day')">Día</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$mode === 'week'" wire:click="$set('mode', 'week')">Semana</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$mode === 'list'" wire:click="$set('mode', 'list')">Lista</x-filament::tabs.item>
                </x-filament::tabs>

                <button
                    type="button"
                    class="agenda-find-toggle"
                    x-on:click="railOpen = ! railOpen"
                    :aria-expanded="railOpen.toString()"
                    aria-expanded="false"
                    aria-controls="agenda-rail"
                >
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="agenda-nav-icon" />
                    Buscar hueco
                </button>

                <div class="agenda-nav agenda-rail-toggle">
                    <button
                        type="button"
                        class="agenda-nav-btn is-icon"
                        x-on:click="railHidden = ! railHidden; try { localStorage.setItem('agenda-rail', railHidden ? 'hidden' : 'shown') } catch (e) {}"
                        :aria-pressed="(! railHidden).toString()"
                        :title="railHidden ? 'Mostrar el panel para buscar horas libres' : 'Esconder el panel para buscar horas libres'"
                        aria-label="Panel para buscar horas libres"
                    >
                        <x-filament::icon icon="heroicon-m-view-columns" class="agenda-nav-icon" />
                    </button>
                </div>
            </div>
        </div>

        <div class="agenda-info">
            <div class="agenda-legend">
                @foreach ($legend as $class => $label)
                    <span class="{{ $class }}"><i aria-hidden="true"></i>{{ $label }}</span>
                @endforeach
            </div>
            {{-- La ayuda se abre cuando se necesita: el que usa la agenda todos los días ya la sabe. En el celular, los colores van aquí. --}}
            <details class="agenda-help">
                <summary><span class="agenda-help-long">Cómo usar la agenda</span><span class="agenda-help-short">Ayuda</span></summary>
                <p>Toca una cita para ver sus datos y botones, o un espacio libre para agendar ahí. Arrastra una cita para cambiarla de horario (en celular, mantenla presionada y luego arrástrala).</p>
                <p>"Sin cerrar": ya pasó su hora y falta marcar si vino. Ábrela y usa "Llegó" y "Atendido", o "No asistió".</p>
                <p>En la semana no entran las etiquetas: un punto rojo en la cita avisa que está sin cerrar, que toca llamar para confirmarla o que cayó en un bloqueo. Ábrela para ver cuál.</p>
                <p>"En bloqueo": ese horario se bloqueó con la cita ya dada. Muévela para que el paciente reciba el aviso.</p>
                <p>¿Marcaste "Llegó", "No asistió", "Confirmar" o "Cancelar" por error? Tienes un día para usar "Deshacer" en la misma cita.</p>
                <div class="agenda-legend">
                    @foreach ($legend as $class => $label)
                        <span class="{{ $class }}"><i aria-hidden="true"></i>{{ $label }}</span>
                    @endforeach
                </div>
            </details>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            /** El día en lista: se refresca solo como la grilla y, al llegar, baja hasta la línea de ahora si no se ve. */
            Alpine.data('agendaList', () => ({
                refresher: null,

                init() {
                    this.refresher = setInterval(() => document.hidden || this.refresh(), 30000);
                },

                /** Cada 30 s y apenas otra persona guarda algo (el pulso del panel), salvo con una ventana abierta. */
                refresh() {
                    if (this.$wire.mountedActions?.length) return;
                    this.$wire.$refresh();
                },

                destroy() {
                    clearInterval(this.refresher);
                },

                arrive() {
                    this.$nextTick(() => {
                        const now = this.$root.querySelector('.agenda-list-now');
                        if (now && now.getBoundingClientRect().top > window.innerHeight - 120) {
                            window.scrollTo({ top: window.scrollY + now.getBoundingClientRect().top - window.innerHeight / 4 });
                        }
                    });
                },

                /** Abre el panel de la cita. Con Ctrl o ⌘ se abre la ficha completa en otra pestaña. */
                open(event, id) {
                    if (event.ctrlKey || event.metaKey || event.shiftKey) return;
                    event.preventDefault();
                    this.$wire.mountAction('appointment', { id });
                },
            }));

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
                preview: { top: 0, height: 0, time: '', outside: false },
                hover: null,
                refresher: null,

                init() {
                    this.refresher = setInterval(() => document.hidden || this.refresh(), 30000);
                },

                /**
                 * Se refresca sola cada 30 s y apenas otra persona guarda algo (el pulso del panel), salvo
                 * mientras alguien arrastra una cita o tiene una ventana abierta.
                 */
                refresh() {
                    if (this.press || this.dragging || this.$wire.mountedActions?.length) return;
                    this.$wire.$refresh();
                },

                /** Cada vez que se cambia de día o de vista, la grilla va a hoy y a la hora actual. */
                arrive() {
                    this.$nextTick(() => {
                        this.scrollToToday();
                        this.scrollToNow();
                    });
                },

                /** En la semana, si no entran todos los días (en el celular), la grilla empieza en hoy; en otra semana, en el lunes. */
                scrollToToday() {
                    if (this.$root.scrollWidth <= this.$root.clientWidth + 1) return;
                    const today = this.$root.querySelector('.agenda-head.is-today');
                    this.$root.scrollLeft = today ? today.offsetLeft - this.$root.querySelector('.agenda-times').offsetWidth : 0;
                },

                scrollToNow() {
                    const now = this.$root.querySelector('.agenda-now');
                    if (! now) return;
                    if (this.scrollsAlone()) {
                        const top = now.getBoundingClientRect().top - this.$root.getBoundingClientRect().top + this.$root.scrollTop;
                        this.$root.scrollTop = Math.max(0, top - this.$root.clientHeight / 3);
                    } else if (now.getBoundingClientRect().top > window.innerHeight - 120) {
                        window.scrollTo({ top: window.scrollY + now.getBoundingClientRect().top - window.innerHeight / 3 });
                    }
                },

                /** La grilla se desplaza sola (computadora, semana) o con la página (el día en el celular). */
                scrollsAlone() {
                    return this.$root.scrollHeight > this.$root.clientHeight + 1;
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

                    return Math.max(0, Math.min(24 * 60 - 15, Math.round(minutes / 15) * 15));
                },

                label(minutes) {
                    return String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
                },

                /**
                 * Con el mouse sobre un hueco libre del horario de atención, la cita nueva se ve antes de hacer clic
                 * (en cuartos de hora, hasta media hora o lo que quede libre). Sobre citas, bloqueos o fuera de horario no aparece.
                 */
                hoverAt(event, column) {
                    this.hover = null;
                    if (event.pointerType !== 'mouse' || this.press || this.dragging || event.target.closest('.agenda-appt')) return;
                    const start = Math.floor((this.from + (event.clientY - column.getBoundingClientRect().top) / this.scale) / 15) * 15;
                    const range = JSON.parse(column.dataset.working).find(([from, to]) => start >= from && start + 15 <= to);
                    const busy = JSON.parse(column.dataset.busy);
                    if (! range || busy.some(([from, to]) => start < to && start + 15 > from)) return;
                    const end = Math.min(start + 30, range[1], ...busy.map(([from]) => from).filter((from) => from > start));
                    this.hover = { key: column.dataset.key, top: (start - this.from) * this.scale, height: (end - start) * this.scale - 2, time: this.label(start) };
                },

                create(event, column, doctor, date) {
                    if (event.target.closest('.agenda-appt') || this.justDropped()) return;
                    const time = this.hover?.key === column.dataset.key ? this.hover.time : this.label(this.minuteAt(event.clientY, column));
                    this.$wire.mountAction('createAppointment', { doctor, date, time });
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
                    this.hover = null;
                    this.press = {
                        id: Number(card.dataset.id),
                        key: card.closest('.agenda-col').dataset.key,
                        doctor: card.closest('.agenda-col').dataset.doctor,
                        treatment: card.dataset.treatment,
                        time: card.dataset.start,
                        minutes: Number(card.dataset.minutes),
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
                    // Fuera del horario de atención se puede soltar igual, pero se ve antes (y se vuelve a preguntar).
                    const inside = JSON.parse(column.dataset.working || '[]').some(([from, to]) => minutes >= from && minutes + this.dragging.minutes <= to);
                    this.preview = { top: (minutes - this.from) * this.scale, height: this.dragging.height, time: this.label(minutes), outside: ! inside };
                },

                autoScroll() {
                    if (! this.dragging) return;
                    const frame = this.$root.getBoundingClientRect();
                    const { clientX: x, clientY: y } = this.pointer;
                    const dy = y > Math.min(frame.bottom, window.innerHeight) - 40 ? 10 : (y < Math.max(frame.top, 0) + 90 ? -10 : 0);
                    const dx = x > frame.right - 40 ? 10 : (x < frame.left + 90 ? -10 : 0);
                    if (dx || dy) {
                        this.$root.scrollBy(dx, this.scrollsAlone() ? dy : 0);
                        if (! this.scrollsAlone()) window.scrollBy(0, dy);
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
    @elseif ($mode === 'list')
        @include('filament.pages.agenda-list', ['list' => $list])
    @else
        @php
            $height = ($to - $from) * $scale;
            $nowMinute = now()->hour * 60 + now()->minute;
            $columnWidth = $mode === 'week' ? '7rem' : '9.5rem';
            $showsNow = $nowMinute > $from && $nowMinute < $to && collect($columns)->contains(fn (array $column) => $column['date'] === today()->toDateString());
        @endphp

        {{-- Con una o dos columnas del día, en el celular la grilla no tiene su propio desplazamiento: se baja con la página. --}}
        {{-- Con su propia llave: al venir de "Lista" la grilla se arma de cero, con arrastrar y tocar funcionando. --}}
        <div
            wire:key="agenda-frame"
            @class(['agenda-frame', 'is-fit' => $mode === 'day' && count($columns) <= 2])
            :class="{ 'is-dragging': dragging }"
            data-from="{{ $from }}"
            data-scale="{{ $scale }}"
            x-data="agendaGrid"
            x-on:{{ \App\Filament\Pages\Dashboard::CHANGED }}.window="refresh()"
            x-on:pointermove.window="move($event)"
            x-on:pointerup.window="end()"
            x-on:pointercancel.window="cancel()"
            x-on:keydown.escape.window="cancel()"
            x-on:touchmove="if (dragging) $event.preventDefault()"
            x-on:contextmenu="if (press || dragging) $event.preventDefault()"
        >
            {{-- Tan ancha como todas sus columnas: si no entran, las horas quedan pegadas a la izquierda hasta el último día. --}}
            <div class="agenda-grid" wire:key="grid-{{ $mode }}-{{ $date }}" x-init="arrive()" style="grid-template-columns: 3.25rem repeat({{ count($columns) }}, minmax({{ $columnWidth }}, 1fr)); width: max(100%, calc(3.25rem + {{ count($columns) }} * {{ $columnWidth }})); --hour: {{ 60 * $scale }}px;">
                <div class="agenda-head agenda-corner"></div>

                @foreach ($columns as $column)
                    @php
                        $count = count($column['appointments']);
                        $countLabel = $count === 0 ? 'Sin citas' : $count.' '.($count === 1 ? 'cita' : 'citas');
                    @endphp
                    {{-- En la semana, el número del día grande (hoy, en un círculo); en el día, el doctor con sus iniciales. --}}
                    <div @class(['agenda-head', 'is-week' => $mode === 'week', 'is-today' => $column['isToday'] && $mode === 'week', 'is-past' => $column['isPast'] && $mode === 'week'])>
                        @if ($mode === 'week')
                            <span class="agenda-head-num" @if ($column['isToday']) aria-label="Hoy" @endif>{{ $column['dayNumber'] }}</span>
                            <span class="agenda-head-text">
                                <span class="agenda-head-title">{{ $column['weekday'] }}</span>
                                <span class="agenda-head-sub">{{ $countLabel }}</span>
                            </span>
                        @else
                            <span class="agenda-head-avatar" style="--doc: {{ $column['color'] }}" aria-hidden="true">{{ $column['initials'] }}</span>
                            <span class="agenda-head-text">
                                <span class="agenda-head-title">{{ $column['title'] }}</span>
                                <span class="agenda-head-sub">{{ collect([$column['subtitle'], $countLabel])->filter()->implode(' · ') }}</span>
                            </span>
                        @endif
                    </div>
                @endforeach

                <div class="agenda-times" style="height: {{ $height }}px">
                    @foreach ($hours as $hour)
                        <span @class(['is-first' => $hour * 60 === $from, 'is-hidden' => $showsNow && abs($hour * 60 - $nowMinute) < 12]) style="top: {{ ($hour * 60 - $from) * $scale }}px">{{ sprintf('%02d:00', $hour) }}</span>
                    @endforeach
                    @if ($showsNow)
                        <span class="agenda-now-label" style="top: {{ ($nowMinute - $from) * $scale }}px">{{ now()->format('H:i') }}</span>
                    @endif
                </div>

                @foreach ($columns as $column)
                    <div
                        wire:key="col-{{ $column['key'] }}"
                        class="agenda-col"
                        data-key="{{ $column['key'] }}"
                        data-doctor="{{ $column['doctorId'] }}"
                        data-treatments="{{ implode(',', $column['treatments']) }}"
                        data-working="{{ json_encode(array_map(fn (array $range) => [$range['start'], $range['end']], $column['working'])) }}"
                        data-busy="{{ json_encode([...array_map(fn (array $appt) => [$appt['start'], $appt['until']], $column['appointments']), ...array_map(fn (array $block) => [$block['start'], $block['end']], $column['blocks'])]) }}"
                        :class="{ 'is-over': over === @js($column['key']), 'is-refused': refused === @js($column['key']) }"
                        style="height: {{ $height }}px"
                        x-on:click="create($event, $el, {{ $column['doctorId'] }}, @js($column['date']))"
                        x-on:pointermove="hoverAt($event, $el)"
                        x-on:pointerleave="hover = null"
                    >
                        <div
                            class="agenda-ghost"
                            x-cloak
                            x-show="hover?.key === @js($column['key'])"
                            :style="{ top: (hover?.top ?? 0) + 'px', height: (hover?.height ?? 0) + 'px' }"
                        ><span x-text="'+ ' + (hover?.time ?? '')"></span></div>

                        <div
                            class="agenda-drop"
                            x-cloak
                            x-show="over === @js($column['key']) || refused === @js($column['key'])"
                            :class="{ 'is-refused': refused === @js($column['key']), 'is-outside': preview.outside }"
                            :style="{ top: preview.top + 'px', height: preview.height + 'px' }"
                        ><span x-text="refused === @js($column['key']) ? 'No realiza este tratamiento' : preview.time + (preview.outside ? ' · fuera de horario' : '')"></span></div>

                        @foreach ($column['working'] as $range)
                            <div class="agenda-work" style="top: {{ ($range['start'] - $from) * $scale }}px; height: {{ ($range['end'] - $range['start']) * $scale }}px"></div>
                        @endforeach

                        @foreach ($column['blocks'] as $block)
                            <div @class(['agenda-block', 'is-hold' => $block['hold']]) style="top: {{ (max($block['start'], $from) - $from) * $scale }}px; height: {{ (min($block['end'], $to) - max($block['start'], $from)) * $scale }}px">{{ $block['label'] }}</div>
                        @endforeach

                        @if ($column['date'] === today()->toDateString() && $nowMinute > $from && $nowMinute < $to)
                            <div class="agenda-now" style="top: {{ ($nowMinute - $from) * $scale }}px"></div>
                        @endif

                        @foreach ($column['appointments'] as $appt)
                            <div class="agenda-buffer" style="top: {{ ($appt['end'] - $from) * $scale }}px; height: {{ ($appt['until'] - $appt['end']) * $scale }}px"></div>
                            <a
                                wire:key="appt-{{ $appt['id'] }}"
                                href="{{ $appt['url'] }}"
                                @class(['agenda-appt', 's-'.$appt['status'], 'is-movable' => $appt['draggable'], 'is-short' => $appt['end'] - $appt['start'] <= 30, 'is-unclosed' => $appt['unclosed'], 'needs-call' => $appt['needsCall'], 'in-block' => $appt['inBlock'], 'in-lane' => $appt['lanes'] > 1])
                                style="top: {{ ($appt['start'] - $from) * $scale }}px; height: {{ max(($appt['end'] - $appt['start']) * $scale - 2, 18) }}px;@if ($appt['lanes'] > 1) --lane: {{ $appt['lane'] }}; --lanes: {{ $appt['lanes'] }};@endif"
                                title="{{ $appt['time'] }} · {{ $appt['patient'] }} · {{ $appt['treatment'] }} · {{ $appt['statusLabel'] }}{{ $appt['phone'] ? ' · '.$appt['phone'] : '' }}{{ $appt['unclosed'] ? ' · Sin cerrar: '.(in_array($appt['status'], ['pending', 'confirmed'], true) ? 'falta marcar si vino' : 'vino y falta cerrar su atención') : '' }}{{ $appt['inBlock'] ? ' · Cae en un horario bloqueado: hay que moverla' : '' }}"
                                draggable="false"
                                x-on:click="open($event, {{ $appt['id'] }})"
                                @if ($appt['draggable'])
                                    data-id="{{ $appt['id'] }}"
                                    data-treatment="{{ $appt['treatmentId'] }}"
                                    data-start="{{ sprintf('%02d:%02d', intdiv($appt['start'], 60), $appt['start'] % 60) }}"
                                    data-minutes="{{ $appt['end'] - $appt['start'] }}"
                                    :class="{ 'is-lifted': dragging && dragging.id === {{ $appt['id'] }} }"
                                    x-on:pointerdown="start($event, $el)"
                                @endif
                            >
                                {{-- En la semana la columna es angosta: nombre corto y solo la hora de inicio (el alto de la tarjeta ya dice cuánto dura). --}}
                                <b>{{ $mode === 'week' ? $appt['shortName'] : $appt['patient'] }}@if ($appt['unclosed'])<span class="tag tag-unclosed">Sin cerrar</span>@endif @if ($appt['needsCall'])<span class="tag tag-call">Llamar</span>@endif @if ($appt['inBlock'])<span class="tag tag-block">En bloqueo</span>@endif @if ($appt['flags']['alert'])<span @class(['tag', 'tag-alert', 'is-caution' => ! $appt['flags']['alertSevere']]) title="{{ $appt['flags']['alert'] }}">Alerta médica</span>@endif @if ($appt['flags']['reschedule'])<span class="tag tag-change">Pidió cambio</span>@endif @if ($appt['flags']['noShows'])<span class="tag tag-missed">{{ $appt['flags']['noShows'] }}</span>@endif @if ($appt['isNew'])<span class="tag tag-new">Nuevo</span>@endif @if ($appt['owes'])<span class="tag tag-owes">Debe {{ $appt['owes'] }}</span>@endif @if ($appt['hasNote'])<span class="tag tag-note">Nota</span>@endif</b>
                                <small>{{ $mode === 'week' ? $appt['startTime'] : $appt['time'] }} · {{ $appt['treatment'] }}{{ $appt['flags']['session'] ? ' · '.mb_strtolower($appt['flags']['session']) : '' }}</small>
                                <small>{{ $appt['statusLabel'] }}@if ($appt['phone']) · {{ $appt['phone'] }}@endif</small>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    </div>

    @include('filament.pages.agenda-rail', ['rail' => $rail])
    </div>
</x-filament-panels::page>
