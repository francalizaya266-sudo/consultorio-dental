<x-filament-widgets::widget>
    <div class="earnings-grid">
        @foreach ($tables as $table)
            <x-filament::section :heading="$table['title']" :description="$table['key'] === 'period' ? $best : null" @class(['is-wide' => $table['key'] === 'period'])>
                <div @class(['earnings-scroll', 'earnings-period' => $table['key'] === 'period'])>
                    @include('reports.partials.table', ['table' => $table])
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
