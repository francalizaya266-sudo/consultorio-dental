{{-- Una franja delgada por problema: el título se lee de un vistazo y el detalle queda al lado. --}}
<x-filament-widgets::widget>
    <div class="dash-health" role="alert">
        @foreach ($issues as $issue)
            <div class="dash-health-item">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="dash-health-icon" />
                <p>
                    <strong>{{ $issue['heading'] }}.</strong>
                    <span>{{ $issue['description'] }}</span>
                </p>
                @if ($issue['url'])
                    <x-filament::link :href="$issue['url']" size="sm" color="danger">{{ $issue['link'] }}</x-filament::link>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
