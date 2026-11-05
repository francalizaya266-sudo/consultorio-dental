<x-filament-widgets::widget>
    <div class="earnings-grid">
        @foreach ($tables as $table)
            <x-filament::section :heading="$table['title']" @class(['is-wide' => in_array($table['key'], ['payments', 'voided'], true)])>
                <div class="earnings-scroll">
                    @include('reports.partials.table', ['table' => $table, 'empty' => 'Sin pagos este día.'])
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
