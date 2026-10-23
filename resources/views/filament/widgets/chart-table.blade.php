{{-- El gráfico en forma de tabla, para quien no puede verlo. --}}
<table>
    @if ($caption)
        <caption>{{ $caption }}</caption>
    @endif
    <thead>
        <tr>
            <th scope="col"></th>
            @foreach ($datasets as $dataset)
                <th scope="col">{{ $dataset['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($labels as $i => $label)
            <tr>
                <th scope="row">{{ $label }}</th>
                @foreach ($datasets as $dataset)
                    <td>{{ $format($dataset['data'][$i] ?? null) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
