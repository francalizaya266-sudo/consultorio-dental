<x-filament-widgets::widget>
    <style>
        .earnings-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
        @media (min-width: 64rem) { .earnings-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .earnings-grid .is-wide { grid-column: 1 / -1; } }
        .earnings-scroll { overflow-x: auto; }
        .report-table { width: 100%; border-collapse: collapse; font-size: .875rem; font-variant-numeric: tabular-nums; }
        .report-table th, .report-table td { padding: .5rem .75rem; text-align: left; border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
        .report-table th:first-child, .report-table td:first-child { padding-left: 0; }
        .report-table th:last-child, .report-table td:last-child { padding-right: 0; }
        .dark .report-table th, .dark .report-table td { border-color: rgb(255 255 255 / .08); }
        .report-table th { font-size: .75rem; font-weight: 600; color: var(--gray-500); }
        .report-table .num { text-align: right; }
        .report-table tfoot td { font-weight: 700; color: var(--gray-950); border-bottom: 0; border-top: 1px solid var(--gray-300); }
        .dark .report-table tfoot td { color: #fff; border-color: rgb(255 255 255 / .2); }
        .report-table .empty { text-align: center; color: var(--gray-500); padding: 1.5rem; }
        .earnings-period { max-height: 26rem; overflow-y: auto; }
        .earnings-period thead th, .earnings-period tfoot td { position: sticky; background: #fff; }
        .earnings-period thead th { top: 0; }
        .earnings-period tfoot td { bottom: 0; }
        .dark .earnings-period thead th, .dark .earnings-period tfoot td { background: var(--gray-900); }
    </style>

    <div class="earnings-grid">
        @foreach ($tables as $table)
            <x-filament::section :heading="$table['title']" @class(['is-wide' => $table['key'] === 'period'])>
                <div @class(['earnings-scroll', 'earnings-period' => $table['key'] === 'period'])>
                    @include('reports.partials.table', ['table' => $table])
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
