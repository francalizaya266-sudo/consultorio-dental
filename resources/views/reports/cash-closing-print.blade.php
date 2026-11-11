@use('App\Support\Money')
@use('App\Filament\Widgets\CashClosingCheck')
<x-print-layout title="Cierre del día">
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
        .count { margin-top: 22px; border: 1px solid var(--line); border-radius: 6px; padding: 10px 12px; break-inside: avoid; }
        .count h2 { margin-bottom: 10px; }
        .count-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 24px; }
        .count-grid span { display: block; font-size: .75rem; color: var(--muted); }
        .count-grid .line { display: block; border-bottom: 1px solid var(--ink); height: 22px; }
        .count-notes { margin-top: 12px; }
        .count-notes .line { display: block; border-bottom: 1px solid var(--line); height: 22px; }
        .owed { margin-top: 18px; break-inside: avoid; }
        .owed .num { text-align: right; white-space: nowrap; }
        .fine { margin-top: 16px; font-size: .8rem; color: var(--muted); }
        @media print { .tables section { break-inside: avoid; } }
    </style>

    <div class="period">{{ $closing->label() }}</div>

    <div class="totals">
        <div><span>Cobrado</span><strong>{{ Money::format($closing->total()) }}</strong></div>
        <div><span>Pagos</span><strong>{{ number_format($closing->payments()->count()) }}</strong></div>
        <div><span>Efectivo en caja</span><strong>{{ Money::format($closing->cashInDrawer()) }}</strong></div>
        <div><span>Otros medios</span><strong>{{ Money::format($closing->total() - $closing->cash()) }}</strong></div>
    </div>

    <div class="tables">
        @foreach ($closing->tables() as $table)
            <section @class(['wide' => in_array($table['key'], ['payments', 'voided'], true)])>
                <h2>{{ $table['title'] }}</h2>
                @include('reports.partials.table', ['table' => $table, 'empty' => 'Sin pagos este día.'])
            </section>
        @endforeach
    </div>

    @if ($closing->receivedBy === null && $closing->pending()->isNotEmpty())
        <section class="owed">
            <h2>Se fueron debiendo · {{ Money::format($closing->pendingTotal()) }}</h2>
            <table class="report-table">
                <tbody>
                    @foreach ($closing->pending() as $row)
                        <tr>
                            <td>{{ $row['patient']->full_name }}</td>
                            <td class="muted">{{ $row['concepts'] }}</td>
                            <td class="num">{{ Money::format($row['due']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- Con el día cerrado, el arqueo ya está hecho: se imprime lo que se contó. --}}
    @php($record = $closing->receivedBy === null ? $closing->record() : null)
    <section class="count">
        <h2>Arqueo del efectivo</h2>
        <div class="count-grid">
            @if ($record)
                <div><span>Fondo</span><strong>{{ Money::format($record->opening_cash) }}</strong></div>
                <div><span>Debía haber</span><strong>{{ Money::format($record->expected_cash) }}</strong></div>
                <div><span>Contado</span><strong>{{ Money::format($record->counted_cash) }}</strong></div>
            @else
                <div><span>Fondo</span><span class="line"></span></div>
                <div><span>Según el sistema (sin el fondo)</span><strong>{{ Money::format($closing->cashInDrawer()) }}</strong></div>
                <div><span>Contado</span><span class="line"></span></div>
            @endif
        </div>
        <div class="count-notes">
            @if ($record)
                <strong>{{ CashClosingCheck::differenceText($record->difference()) }}</strong>
                <span class="muted">Cerró {{ $record->closedBy?->name ?? '—' }} a las {{ $record->created_at->format('H:i') }}.{{ $record->notes ? ' '.$record->notes : '' }}</span>
            @else
                <span class="muted">Diferencia y observaciones</span>
                <span class="line"></span>
                <span class="line"></span>
            @endif
        </div>
    </section>

    <x-slot:footer>
        <div class="signatures">
            <div class="signature">Entregó</div>
            <div class="signature">Recibió</div>
        </div>
        <p class="fine">
            Solo los pagos registrados en Caja con fecha de este día. Lo anulado se lista aparte y no suma.
            Impreso el {{ now()->format('d/m/Y H:i') }}.
        </p>
    </x-slot:footer>
</x-print-layout>
