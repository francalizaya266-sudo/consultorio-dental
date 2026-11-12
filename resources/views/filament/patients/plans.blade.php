{{-- Los presupuestos y planes del paciente en su ficha: qué incluye cada uno, en qué quedó y cuánto suma. Cada uno abre su página. --}}
<ul class="pt-plans">
    @foreach ($rows as $row)
        <li>
            <a href="{{ $row['url'] }}" @class(['pt-plan', 'is-closed' => ! $row['open']])>
                <span class="pt-plan-top">
                    <b>{{ $row['summary'] }}</b>
                    <span class="pt-plan-total">{{ $row['total'] }}</span>
                </span>
                <span class="pt-plan-state">
                    <x-filament::badge :color="$row['status']->getColor()" size="sm">{{ $row['status']->getLabel() }}</x-filament::badge>
                    @if ($row['since'])
                        <small>{{ $row['since'] }}</small>
                    @endif
                </span>
                @if ($row['payment'])
                    <span class="pt-plan-pay">{{ $row['payment'] }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>
