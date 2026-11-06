{{--
    Barra fija de la atención, que hace de encabezado: quién es y qué se le hace hoy, sus alertas, el consentimiento,
    las herramientas, Terminar y las secciones (marca dónde estás y cuáles ya tienen algo escrito).
--}}
<div
    class="consultation-bar"
    x-data="{
        ids: @js(array_keys($sections)),
        current: null,
        timer: null,
        ticking: false,
        init() {
            // Guardado automático: cada 15 s, si algo cambió (lo decide el servidor).
            this.timer = setInterval(() => {
                if (document.hidden || this.$wire.mountedActions?.length) return;
                this.$wire.autosave();
            }, 15000);
            this.onScroll = () => {
                if (this.ticking) return;
                this.ticking = true;
                requestAnimationFrame(() => { this.ticking = false; this.measure(); this.spy(); });
            };
            window.addEventListener('scroll', this.onScroll, { passive: true });
            window.addEventListener('resize', this.onScroll);
            this.observer = new ResizeObserver(this.onScroll);
            this.observer.observe(this.$el);
            const left = this.leftColumn();
            if (left) this.observer.observe(left);
            this.$nextTick(this.onScroll);
        },
        destroy() {
            clearInterval(this.timer);
            window.removeEventListener('scroll', this.onScroll);
            window.removeEventListener('resize', this.onScroll);
            this.observer?.disconnect();
        },
        leftColumn() {
            return document.querySelector('.consultation-split > .fi-grid > .fi-grid-col');
        },
        // Hasta dónde tapa la barra: para saltar a una sección y para fijar el odontograma justo debajo.
        // Si el odontograma es más alto que la pantalla, se fija por abajo para que se pueda ver entero.
        measure() {
            const offset = (document.querySelector('.fi-topbar')?.offsetHeight ?? 64) + this.$el.offsetHeight + 12;
            const root = document.documentElement.style;
            root.setProperty('--cb-offset', offset + 'px');
            const left = this.leftColumn();
            if (left) root.setProperty('--split-top', Math.min(offset, window.innerHeight - left.offsetHeight - 16) + 'px');
        },
        spy() {
            const line = (document.querySelector('.fi-topbar')?.offsetHeight ?? 64) + this.$el.offsetHeight + 32;
            const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
            let current = null;
            for (const id of this.ids) {
                const el = document.getElementById(id);
                if (el && el.getBoundingClientRect().top <= line) current = id;
            }
            this.show(atBottom ? this.ids[this.ids.length - 1] : current);
        },
        show(id) {
            if (id === this.current) return;
            this.current = id;
            const nav = this.$refs.nav;
            const chip = nav?.querySelector(`[data-section='${id}']`);
            if (chip && (chip.offsetLeft < nav.scrollLeft || chip.offsetLeft + chip.offsetWidth > nav.scrollLeft + nav.clientWidth)) {
                nav.scrollTo({ left: chip.offsetLeft - 16, behavior: 'smooth' });
            }
        },
        go(id) {
            this.show(id);
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
    }"
>
    <div class="cb-top">
        <div class="cb-who">
            @if ($patient)
                <h1 class="cb-name">{{ $patient->full_name }}</h1>
            @endif
            @if ($meta)
                <p class="cb-meta">{{ $meta }}</p>
            @endif
        </div>

        <div class="cb-actions">
            <span class="cb-saved" wire:loading.remove wire:target="autosave, save">
                {{ $autosavedAt ? 'Guardado a las '.$autosavedAt : 'Se guarda solo' }}
            </span>
            <span class="cb-saved" wire:loading wire:target="autosave, save">Guardando…</span>

            <div class="cb-tools">
                <x-filament::button size="xs" color="gray" outlined wire:click="save" wire:target="save">Guardar</x-filament::button>
                <x-filament::button size="xs" color="gray" icon="heroicon-o-document-duplicate" tooltip="Usar plantilla" aria-label="Usar plantilla" wire:click="mountAction('template')"><span class="cb-tool-label">Plantilla</span></x-filament::button>
                <x-filament::button size="xs" color="gray" icon="heroicon-o-pencil" tooltip="Firmar consentimiento" aria-label="Firmar consentimiento" wire:click="mountAction('signConsent')"><span class="cb-tool-label">Consentimiento</span></x-filament::button>
                @if ($historyUrl)
                    <x-filament::button size="xs" color="gray" icon="heroicon-o-folder-open" tooltip="Historia clínica (se abre en otra pestaña)" tag="a" :href="$historyUrl" aria-label="Historia clínica" target="_blank"><span class="cb-tool-label">Historia</span></x-filament::button>
                @endif
            </div>

            <x-filament::dropdown placement="bottom-end" class="cb-menu">
                <x-slot name="trigger">
                    <x-filament::icon-button icon="heroicon-m-ellipsis-vertical" color="gray" label="Más opciones" />
                </x-slot>
                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item icon="heroicon-o-arrow-down-tray" wire:click="save">Guardar ahora</x-filament::dropdown.list.item>
                    <x-filament::dropdown.list.item icon="heroicon-o-document-duplicate" wire:click="mountAction('template')">Usar plantilla</x-filament::dropdown.list.item>
                    <x-filament::dropdown.list.item icon="heroicon-o-pencil" wire:click="mountAction('signConsent')">Firmar consentimiento</x-filament::dropdown.list.item>
                    @if ($historyUrl)
                        <x-filament::dropdown.list.item icon="heroicon-o-folder-open" tag="a" :href="$historyUrl" target="_blank">Historia clínica</x-filament::dropdown.list.item>
                    @endif
                </x-filament::dropdown.list>
            </x-filament::dropdown>

            <x-filament::button size="sm" color="success" icon="heroicon-o-check-circle" class="cb-finish" x-on:click="$wire.mountAction('close')">
                <span class="cb-long">Terminar atención</span><span class="cb-short">Terminar</span>
            </x-filament::button>
        </div>

        @if ($patient)
            <div class="cb-flags">
                @foreach ($alerts as $alert)
                    <span class="cb-flag is-danger">⚠ {{ $alert }}</span>
                @endforeach
                @if ($patient && ! $hasBackground)
                    <button type="button" class="cb-flag is-warning" wire:click="mountAction('editBackground')">Sin antecedentes · registrar</button>
                @elseif ($patient && $alerts === [])
                    <span class="cb-flag is-ok">Sin alergias ni enfermedades</span>
                @endif
                @if ($consent === true)
                    <span class="cb-flag is-ok">Consentimiento firmado</span>
                @elseif ($consent === false)
                    <button type="button" class="cb-flag is-warning" wire:click="mountAction('signConsent')">Falta firmar el consentimiento</button>
                @endif
            </div>
        @endif
    </div>

    <nav class="cb-sections" aria-label="Secciones de la atención" x-ref="nav">
        @foreach ($sections as $id => $label)
            <a
                href="#{{ $id }}"
                data-section="{{ $id }}"
                @if (in_array($id, $filled, true)) data-filled @endif
                :class="{ 'is-current': current === @js($id) }"
                :aria-current="current === @js($id) ? 'location' : null"
                x-on:click.prevent="go(@js($id))"
            >{{ $label }}@if (in_array($id, $filled, true))<span class="cb-sr"> (tiene datos)</span>@endif</a>
        @endforeach
    </nav>
</div>
