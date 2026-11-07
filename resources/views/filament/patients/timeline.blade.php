{{--
    Línea de tiempo del paciente, agrupada por día, con un filtro por tipo. Cada pago lleva su constancia y, en la
    ficha, "Anular". Se ven los últimos días; el resto, con "Ver toda la historia" o al filtrar por un tipo.
--}}
@php($kinds = $events->countBy('kind'))
@php($days = $events->groupBy(fn (array $event) => $event['at']->toDateString()))
@php($shownDays = 8)
<div class="pt-timeline" x-data="{ kind: null, all: false }">
    @if ($kinds->count() > 1)
        <div class="pt-filter" role="group" aria-label="Qué mostrar">
            <button type="button" class="pt-filter-chip" x-bind:aria-pressed="(kind === null).toString()" x-on:click="kind = null">Todo</button>
            @foreach (\App\Filament\Support\PatientTimeline::KINDS as $kind => $label)
                @if ($kinds->has($kind))
                    <button type="button" class="pt-filter-chip" x-bind:aria-pressed="(kind === @js($kind)).toString()" x-on:click="kind = @js($kind)">
                        {{ $label }} <span>{{ $kinds[$kind] }}</span>
                    </button>
                @endif
            @endforeach
        </div>
    @endif

    @forelse ($days as $day => $dayEvents)
        <section class="pt-day" x-show="kind === null ? (all || {{ $loop->index }} < {{ $shownDays }}) : @js($dayEvents->pluck('kind')->unique()->values()).includes(kind)" @if ($loop->index >= $shownDays) x-cloak @endif>
            <h4>{{ ucfirst(\Carbon\CarbonImmutable::parse($day)->translatedFormat('l j \d\e F \d\e Y')) }}</h4>
            @foreach ($dayEvents as $event)
                @php($tag = $event['url'] ? 'a' : 'div')
                <{{ $tag }} @if ($event['url']) href="{{ $event['url'] }}" @endif class="pt-event" x-show="kind === null || kind === @js($event['kind'])">
                    <span class="pt-icon c-{{ $event['color'] }}"><x-filament::icon :icon="$event['icon']" /></span>
                    <span class="pt-text">
                        <b>{{ $event['title'] }}</b>
                        @if (filled($event['detail']))
                            <small>{{ $event['detail'] }}</small>
                        @endif
                        @if ($event['receipt'])
                            <span class="pt-event-actions">
                                <a href="{{ $event['receipt'] }}" target="_blank">Constancia</a>
                                @if ($canVoid && $event['payment'])
                                    <button type="button" wire:click="mountAction('voidPayment', {{ \Illuminate\Support\Js::from(['payment' => $event['payment']]) }})">Anular</button>
                                @endif
                            </span>
                        @endif
                    </span>
                    <span class="pt-meta">{{ $event['kind'] }} · {{ $event['at']->format('H:i') }}</span>
                </{{ $tag }}>
            @endforeach
        </section>
    @empty
        <p class="pt-empty">Todavía no hay citas, atenciones, pagos ni mensajes.</p>
    @endforelse

    @if ($days->count() > $shownDays)
        <button type="button" class="pt-more" x-show="kind === null && ! all" x-on:click="all = true">
            Ver toda la historia ({{ $days->count() - $shownDays }} {{ $days->count() - $shownDays === 1 ? 'día' : 'días' }} más)
        </button>
    @endif
</div>
