{{-- Línea de tiempo del paciente, agrupada por día. --}}
<style>
    .pt-timeline { display: grid; gap: 1rem; }
    .pt-day { display: grid; gap: .35rem; }
    .pt-day h4 { margin: 0; font-size: .75rem; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--gray-500); }
    .pt-event { display: grid; grid-template-columns: 2rem 1fr auto; gap: .6rem; align-items: start; padding: .5rem .6rem; border-radius: .6rem; text-decoration: none; color: inherit; }
    a.pt-event:hover { background: var(--gray-50); }
    .dark a.pt-event:hover { background: rgb(255 255 255 / .04); }
    .pt-icon { width: 2rem; height: 2rem; border-radius: 9999px; display: grid; place-items: center; background: var(--gray-100); color: var(--gray-500); }
    .pt-icon svg { width: 1rem; height: 1rem; }
    .pt-icon.c-success { background: var(--success-50); color: var(--success-600); }
    .pt-icon.c-warning { background: var(--warning-50); color: var(--warning-600); }
    .pt-icon.c-danger { background: var(--danger-50); color: var(--danger-600); }
    .pt-icon.c-info, .pt-icon.c-primary { background: var(--primary-50); color: var(--primary-600); }
    .dark .pt-icon { background: rgb(255 255 255 / .06); }
    .pt-text b { display: block; font-size: .875rem; font-weight: 600; color: var(--gray-950); }
    .dark .pt-text b { color: #fff; }
    .pt-text small { display: block; font-size: .78rem; color: var(--gray-500); }
    .pt-meta { font-size: .72rem; color: var(--gray-500); white-space: nowrap; text-align: right; }
    .pt-empty { color: var(--gray-500); font-size: .875rem; }
</style>

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
