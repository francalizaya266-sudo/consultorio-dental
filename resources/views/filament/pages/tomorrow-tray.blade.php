{{-- La bandeja del día: una tarjeta por cita con lo que hay que preparar. Se imprime tal cual. --}}
<x-filament-panels::page>
    <div class="tray">
        @forelse ($tray as $visit)
            @php($appointment = $visit['appointment'])
            <section class="tray-visit">
                <header class="tray-visit-head">
                    <span class="tray-time">{{ $appointment->starts_at->format('H:i') }}</span>
                    <span class="tray-who">
                        <b>{{ $appointment->patient?->full_name }}</b>
                        <small>{{ $appointment->treatment?->name }} · {{ $appointment->doctor?->name }}</small>
                    </span>
                </header>

                @if ($visit['alerts'] !== [])
                    <ul class="tray-alerts" aria-label="Antes de preparar">
                        @foreach ($visit['alerts'] as $alert)
                            <li @class(['tray-alert', 'is-severe' => $alert['severe']])>{{ $alert['severe'] ? '⚠ ' : '' }}{{ $alert['text'] }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($visit['items'] === [])
                    <p class="tray-empty">Este tratamiento no tiene materiales cargados.</p>
                @else
                    <ul class="tray-items">
                        @foreach ($visit['items'] as $item)
                            <li @class(['tray-item', 'is-short' => $item['short']])>
                                <span class="tray-check" aria-hidden="true"></span>
                                <span class="tray-item-name">{{ $item['name'] }}</span>
                                <span class="tray-item-qty">{{ $item['quantity'] }}</span>
                                @if ($item['from'] || $item['short'])
                                    <small class="tray-item-from">{{ $item['short'] ?? $item['from'] }}</small>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <p class="tray-none">No hay citas ese día.</p>
        @endforelse
    </div>
</x-filament-panels::page>
