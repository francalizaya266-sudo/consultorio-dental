<x-filament-widgets::widget>
    <x-filament::section heading="Huecos de la semana" icon="heroicon-o-calendar" :description="$summary">
        @if ($days === [])
            <div class="dash-empty">
                <x-filament::icon icon="heroicon-o-calendar" class="dash-empty-icon" />
                <p>No hay horario de atención en los próximos 7 días.</p>
            </div>
        @else
            <ul class="dash-week">
                @foreach ($days as $day)
                    <li>
                        <a href="{{ $day['url'] }}" class="dash-day">
                            <span class="dash-day-head">
                                <b>{{ $day['label'] }}</b>
                                <span class="dash-day-free @if ($day['occupancy'] >= 100) is-full @endif">{{ $day['free'] }}</span>
                            </span>
                            <span class="dash-meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $day['occupancy'] }}" aria-label="{{ $day['occupancy'] }} % ocupado">
                                <span style="width: {{ $day['occupancy'] }}%"></span>
                            </span>
                            @foreach ($day['doctors'] as $doctor)
                                <small>@if ($doctor['name'])<b>{{ $doctor['name'] }}:</b> @endif{{ $doctor['gaps'] }}</small>
                            @endforeach
                        </a>
                        @if ($day['waiting'])
                            <a href="{{ $day['waiting_url'] }}" class="dash-day-wait">
                                <x-filament::icon icon="heroicon-m-queue-list" class="dash-day-wait-icon" />
                                {{ $day['waiting'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
