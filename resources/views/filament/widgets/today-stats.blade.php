<x-filament-widgets::widget>
    <nav class="today-strip" wire:poll.30s aria-label="Resumen del día">
        @foreach ($stats as $stat)
            <a href="{{ $stat['url'] }}" class="today-stat c-{{ $stat['color'] }}" title="{{ $stat['hint'] }}">
                <x-filament::icon :icon="$stat['icon']" />
                <div>
                    <span><b>{{ $stat['value'] }}</b> {{ $stat['label'] }}</span>
                    <small>{{ $stat['hint'] }}</small>
                </div>
            </a>
        @endforeach
    </nav>
</x-filament-widgets::widget>
