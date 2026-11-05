<x-filament-widgets::widget>
    <div class="dash-health">
        @foreach ($issues as $issue)
            <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle" :heading="$issue['heading']" :description="$issue['description']">
                @if ($issue['url'])
                    <x-slot name="footer">
                        <x-filament::link :href="$issue['url']">{{ $issue['link'] }}</x-filament::link>
                    </x-slot>
                @endif
            </x-filament::callout>
        @endforeach
    </div>
</x-filament-widgets::widget>
