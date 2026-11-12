{{-- No se ve: pregunta cada pocos segundos si alguien guardó algo y toma nota de lo que cambió en esta misma pantalla (PanelPulse). --}}
<div
    hidden
    wire:poll.{{ \App\Livewire\PanelPulse::EVERY }}="check"
    x-data
    x-on:{{ \App\Filament\Pages\Dashboard::CHANGED }}.window="$event.target === $el || $wire.acknowledge()"
></div>
