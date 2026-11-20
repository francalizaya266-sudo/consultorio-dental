{{-- Una sola línea con lo primero que hay que revisar y su enlace; el detalle de cada aviso se abre ahí mismo. --}}
@php($first = $issues[0])
<x-filament-widgets::widget>
    <details class="dash-health" role="alert">
        <summary>
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="dash-health-icon" />
            <span class="dash-health-text">
                <strong>{{ $first['heading'] }}</strong>
                @if (count($issues) > 1)
                    <span>y {{ count($issues) - 1 === 1 ? '1 aviso más' : (count($issues) - 1).' avisos más' }}</span>
                @endif
            </span>
            <span class="dash-health-more">Detalles</span>
        </summary>

        <ul>
            @foreach ($issues as $issue)
                <li>
                    <p>
                        <strong>{{ $issue['heading'] }}.</strong>
                        {{ $issue['description'] }}
                    </p>
                    @if ($issue['url'])
                        <x-filament::link :href="$issue['url']" size="sm">{{ $issue['link'] }}</x-filament::link>
                    @endif
                </li>
            @endforeach
        </ul>
    </details>
</x-filament-widgets::widget>
