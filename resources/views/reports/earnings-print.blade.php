@use('App\Support\Money')
<x-print-layout title="Ganancias">
    <style>
        .period { font-size: 1rem; font-weight: 700; margin: -4px 0 12px; }
        .totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin-bottom: 18px; }
        .totals div { padding: 8px 10px; background: #f1f5f9; border-radius: 6px; }
        .totals span { display: block; font-size: .75rem; color: var(--muted); }
        .totals strong { font-size: 1.05rem; }
        .tables { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 24px; align-items: start; }
        .tables section.wide { grid-column: 1 / -1; }
        h2 { font-size: .85rem; text-transform: uppercase; letter-spacing: .04em; color: var(--brand); margin: 0 0 4px; }
        .report-table td, .report-table th { padding: 4px 6px; font-variant-numeric: tabular-nums; }
        .report-table tfoot td { font-weight: 800; border-bottom: 0; border-top: 2px solid var(--ink); }
        .report-table .empty { color: var(--muted); text-align: center; }
        .fine { margin-top: 16px; font-size: .8rem; color: var(--muted); }
        @media print { .tables section { break-inside: avoid; } }
    </style>

    <div class="period">
        {{ $report->period->label() }}{{ $doctor ? ' · '.$doctor->name : '' }}
    </div>

    <div class="totals">
        <div><span>Cobrado</span><strong>{{ Money::format($report->collected()) }}</strong></div>
        <div><span>Realizado</span><strong>{{ Money::format($report->produced()) }}</strong></div>
        <div><span>Pagos</span><strong>{{ number_format($report->paymentsCount()) }}</strong></div>
        @if ($outstanding !== null)
            <div><span>Por cobrar (a hoy)</span><strong>{{ Money::format($outstanding) }}</strong></div>
        @endif
    </div>

    <div class="tables">
        @foreach ($tables as $table)
            <section @class(['wide' => $table['key'] === 'period'])>
                <h2>{{ $table['title'] }}</h2>
                @include('reports.partials.table', ['table' => $table])
            </section>
        @endforeach
    </div>

    <p class="fine">
        Cobrado: pagos registrados en caja. Realizado: lo atendido según los cargos, se haya pagado o no. No incluye lo anulado.
        Impreso el {{ now()->format('d/m/Y H:i') }}.
    </p>
</x-print-layout>
