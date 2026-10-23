@use('App\Services\Reports\EarningsTables')
{{-- Una tabla de EarningsTables. La usan el panel y la hoja para imprimir, cada uno con sus estilos. --}}
<table class="report-table">
    <thead>
        <tr>
            @foreach ($table['columns'] as $column)
                <th @class(['num' => $column['type'] !== EarningsTables::TEXT]) scope="col">{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($table['rows'] as $row)
            <tr>
                @foreach ($row as $i => $value)
                    <td @class(['num' => $table['columns'][$i]['type'] !== EarningsTables::TEXT])>{{ EarningsTables::format($value, $table['columns'][$i]['type']) }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($table['columns']) }}" class="empty">Sin movimientos en este período.</td>
            </tr>
        @endforelse
    </tbody>
    @if ($table['total'])
        <tfoot>
            <tr>
                @foreach ($table['total'] as $i => $value)
                    <td @class(['num' => $table['columns'][$i]['type'] !== EarningsTables::TEXT])>{{ EarningsTables::format($value, $table['columns'][$i]['type']) }}</td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>
