{{-- Las atenciones abiertas, la más antigua primero: las de otro día van marcadas porque no se pueden cobrar. --}}
<x-filament-widgets::widget>
    <x-filament::section heading="Atenciones sin terminar" icon="heroicon-o-document-text" wire:poll.30s>
        <x-slot name="afterHeader">
            <span class="dash-count">{{ $count }}</span>
        </x-slot>

        @forelse ($consultations as $consultation)
            <div class="dash-open">
                <div class="dash-row-text">
                    <a href="{{ $consultation['url'] }}" class="dash-name">{{ $consultation['name'] }}</a>
                    <small @class(['dash-late' => $consultation['late']])>{{ $consultation['detail'] }}</small>
                </div>
                <x-filament::link :href="$consultation['url']" size="sm">Continuar</x-filament::link>
            </div>
        @empty
            <p class="dash-hint">Todas tus atenciones están cerradas.</p>
        @endforelse

        @if ($count > count($consultations))
            <x-filament::link :href="$url" size="sm" class="dash-group-more">Ver las {{ $count }}</x-filament::link>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
