{{-- Lo que falta para que la web dé confianza: lo pendiente primero, con su enlace; lo listo, al final con su check. --}}
@php
    $done = collect($items)->where('done', true)->count();
    $sorted = collect($items)->sortBy(fn (array $item) => $item['done'])->values();
@endphp
<section class="setup" aria-labelledby="setup-title">
    <header class="setup-head">
        <div>
            <h2 id="setup-title" class="setup-title">Lo que falta para que la web dé confianza</h2>
            <p class="setup-sub">Cada cosa se hace una sola vez. Cuando esté todo, esta lista desaparece.</p>
        </div>
        <span class="setup-count">{{ $done }} de {{ count($items) }} listos</span>
    </header>
    <div class="setup-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($items) }}" aria-valuenow="{{ $done }}" aria-label="Puesta a punto">
        <span style="width: {{ round($done / max(1, count($items)) * 100) }}%"></span>
    </div>
    <ol class="setup-list">
        @foreach ($sorted as $item)
            <li @class(['setup-item', 'is-done' => $item['done']]) data-setup="{{ $item['key'] }}">
                <x-filament::icon :icon="$item['done'] ? 'heroicon-s-check-circle' : 'heroicon-o-arrow-right-circle'" class="setup-icon" />
                <div class="setup-text">
                    <strong>{{ $item['title'] }}</strong>
                    @unless ($item['done'])
                        <span>{{ $item['why'] }}</span>
                    @endunless
                </div>
                @unless ($item['done'])
                    <x-filament::button size="sm" color="gray" tag="a" :href="$item['url']" class="setup-link">{{ $item['link'] }}</x-filament::button>
                @endunless
            </li>
        @endforeach
    </ol>
</section>
