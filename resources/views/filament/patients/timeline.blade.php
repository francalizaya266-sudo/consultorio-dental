{{-- Línea de tiempo del paciente, agrupada por día. --}}
<div class="pt-timeline">
    @forelse ($events->groupBy(fn (array $event) => $event['at']->toDateString()) as $day => $dayEvents)
        <section class="pt-day">
            <h4>{{ ucfirst(\Carbon\CarbonImmutable::parse($day)->translatedFormat('l j \d\e F \d\e Y')) }}</h4>
            @foreach ($dayEvents as $event)
                @php($tag = $event['url'] ? 'a' : 'div')
                <{{ $tag }} @if ($event['url']) href="{{ $event['url'] }}" @endif class="pt-event">
                    <span class="pt-icon c-{{ $event['color'] }}"><x-filament::icon :icon="$event['icon']" /></span>
                    <span class="pt-text">
                        <b>{{ $event['title'] }}</b>
                        @if (filled($event['detail']))
                            <small>{{ $event['detail'] }}</small>
                        @endif
                    </span>
                    <span class="pt-meta">{{ $event['kind'] }} · {{ $event['at']->format('H:i') }}</span>
                </{{ $tag }}>
            @endforeach
        </section>
    @empty
        <p class="pt-empty">Todavía no hay citas, atenciones, pagos ni mensajes.</p>
    @endforelse
</div>
