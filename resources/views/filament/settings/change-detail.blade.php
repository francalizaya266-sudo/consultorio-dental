{{-- El detalle de un cambio del historial: cada dato con lo que decía antes y lo que dice ahora. --}}
@php
    $created = $event === 'created';
    $deleted = $event === 'deleted';
@endphp
<div style="display: grid; gap: .75rem; font-size: .9rem">
    @if ($changes === [] || $created || $deleted)
        <p style="font-weight: 600">{{ $summary }}</p>
    @endif

    @if ($changes !== [])
        <table style="width: 100%; border-collapse: collapse">
            <thead>
                <tr style="text-align: left; color: var(--gray-500); font-size: .8rem">
                    <th style="padding: .35rem .25rem">Dato</th>
                    @unless ($created)
                        <th style="padding: .35rem .25rem">{{ $deleted ? 'Decía' : 'Antes' }}</th>
                    @endunless
                    @unless ($deleted)
                        <th style="padding: .35rem .25rem">{{ $created ? 'Con qué se creó' : 'Ahora' }}</th>
                    @endunless
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr style="border-top: 1px solid var(--gray-200); vertical-align: top">
                        <td style="padding: .35rem .25rem; font-weight: 600">{{ $change['field'] }}</td>
                        @unless ($created)
                            <td style="padding: .35rem .25rem; white-space: pre-line; color: var(--gray-600)">{{ $change['before'] }}</td>
                        @endunless
                        @unless ($deleted)
                            <td style="padding: .35rem .25rem; white-space: pre-line">{{ $change['after'] }}</td>
                        @endunless
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
