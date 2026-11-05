<x-filament-widgets::widget>
    <x-filament::section heading="Sala de espera" icon="heroicon-o-user-group" wire:poll.30s>
        @forelse ($patients as $patient)
            <a href="{{ $patient['url'] }}" class="dash-waiting">
                <span>
                    <strong>{{ $patient['name'] }}</strong>
                    <small>{{ $patient['detail'] }}</small>
                </span>
                <span @class(['dash-waiting-time', 'is-long' => $patient['long']])>{{ $patient['waited'] }}</span>
            </a>
        @empty
            <p class="dash-hint">Nadie esperando. Cuando alguien llegue, márcalo con "Llegó" en la agenda.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
