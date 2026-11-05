<x-filament-widgets::widget>
    <nav class="dash-kpis" wire:poll.30s aria-label="Resumen del día">
        @foreach ($stats as $stat)
            <a href="{{ $stat['url'] }}" @class(['dash-kpi', 'is-'.$stat['color']])>
                <span class="dash-kpi-label">
                    <x-filament::icon :icon="$stat['icon']" class="dash-kpi-icon" />
                    {{ $stat['label'] }}
                </span>
                <strong class="dash-kpi-value">{{ $stat['value'] }}</strong>
                <small class="dash-kpi-hint">{{ $stat['hint'] }}</small>
            </a>
        @endforeach
    </nav>
</x-filament-widgets::widget>
