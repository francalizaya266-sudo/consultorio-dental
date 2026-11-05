{{-- Barra fija de la atención: el paciente con sus alertas, las secciones (tocar y saltar) y guardar o terminar. --}}
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
