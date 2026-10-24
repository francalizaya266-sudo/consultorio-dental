{{-- Barra fija de la atención: el paciente con sus alertas, las secciones (tocar y saltar) y guardar o terminar. --}}
<style>
    /* Lo que se pega arriba es la caja que Filament pone alrededor (la barra sola no tiene espacio para pegarse). */
    .fi-grid-col:has(> .fi-sc-component > .consultation-bar) { position: sticky; top: var(--topbar-height, 4rem); z-index: 20; }
    .consultation-bar { margin: 0 -.25rem; padding: .6rem .75rem .5rem; border-radius: .9rem; background: rgb(255 255 255 / .96); backdrop-filter: blur(6px); box-shadow: 0 1px 3px rgb(0 0 0 / .08), 0 0 0 1px rgb(0 0 0 / .05); display: grid; gap: .45rem; }
    .dark .consultation-bar { background: rgb(24 24 27 / .96); box-shadow: 0 0 0 1px rgb(255 255 255 / .08); }
    .cb-top { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; justify-content: space-between; }
    .cb-patient { display: flex; flex-wrap: wrap; gap: .35rem .5rem; align-items: center; min-width: 0; }
    .cb-patient strong { font-size: .95rem; color: var(--gray-950); }
    .dark .cb-patient strong { color: #fff; }
    .cb-alert { font-size: .75rem; font-weight: 700; color: var(--danger-700); background: var(--danger-50); border: 1px solid var(--danger-200); border-radius: 9999px; padding: .1rem .55rem; }
    .dark .cb-alert { color: var(--danger-300); background: rgb(239 68 68 / .12); border-color: rgb(239 68 68 / .35); }
    .cb-actions { display: flex; gap: .5rem; align-items: center; margin-left: auto; }
    .cb-saved { font-size: .72rem; color: var(--gray-500); white-space: nowrap; }
    .cb-sections { display: flex; gap: .35rem; overflow-x: auto; scrollbar-width: none; }
    .cb-sections::-webkit-scrollbar { display: none; }
    .cb-sections a { flex: none; font-size: .78rem; font-weight: 500; color: var(--gray-600); text-decoration: none; padding: .3rem .65rem; border-radius: 9999px; background: var(--gray-100); }
    .cb-sections a:hover, .cb-sections a.is-current { background: var(--primary-50); color: var(--primary-700); }
    .dark .cb-sections a { background: rgb(255 255 255 / .06); color: var(--gray-300); }
    .dark .cb-sections a:hover, .dark .cb-sections a.is-current { background: rgb(59 130 246 / .18); color: var(--primary-300); }
    .fi-sc-section[id] { scroll-margin-top: calc(var(--topbar-height, 4rem) + 7rem); }
    /* Tablet horizontal y computadora: odontograma a la izquierda, notas a la derecha. */
    @media (min-width: 1024px) {
        .consultation-split .od-scroll .od-svg { min-width: 26rem; }
    }
    @media (pointer: coarse) {
        .cb-sections a { padding: .5rem .8rem; }
    }
</style>

<div
    class="consultation-bar"
    x-data="{
        timer: null,
        current: null,
        init() {
            // Guardado automático: cada 15 s, si algo cambió (lo decide el servidor).
            this.timer = setInterval(() => {
                if (document.hidden || this.$wire.mountedActions?.length) return;
                this.$wire.autosave();
            }, 15000);
        },
        destroy() {
            clearInterval(this.timer);
        },
        go(id) {
            this.current = id;
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
    }"
>
    <div class="cb-top">
        <div class="cb-patient">
            @if ($patient)
                <strong>{{ $patient->full_name }}</strong>
            @endif
            @foreach ($alerts as $alert)
                <span class="cb-alert">⚠ {{ $alert }}</span>
            @endforeach
        </div>
        <div class="cb-actions">
            <span class="cb-saved" wire:loading.remove wire:target="autosave">
                @if ($autosavedAt)
                    Guardado solo a las {{ $autosavedAt }}
                @endif
            </span>
            <span class="cb-saved" wire:loading wire:target="autosave">Guardando…</span>
            <x-filament::button size="sm" color="gray" wire:click="save" wire:target="save">Guardar</x-filament::button>
            <x-filament::button size="sm" color="success" icon="heroicon-o-check-circle" x-on:click="$wire.mountAction('close')">Terminar atención</x-filament::button>
        </div>
    </div>
    <nav class="cb-sections" aria-label="Secciones de la atención">
        @foreach ($sections as $id => $label)
            <a href="#{{ $id }}" :class="{ 'is-current': current === @js($id) }" x-on:click.prevent="go(@js($id))">{{ $label }}</a>
        @endforeach
    </nav>
</div>
