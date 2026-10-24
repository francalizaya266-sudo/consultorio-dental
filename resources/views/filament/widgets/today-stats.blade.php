<x-filament-widgets::widget>
    <style>
        .today-strip { display: flex; gap: .5rem; overflow-x: auto; scrollbar-width: none; margin: 0 -.25rem; padding: 0 .25rem; }
        .today-strip::-webkit-scrollbar { display: none; }
        /* En el celular la franja se desliza; en pantallas grandes baja a otra línea si no entra. */
        @media (min-width: 48rem) {
            .today-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); overflow: visible; }
            .today-stat small { white-space: normal; }
        }
        .today-stat { flex: 1 0 auto; min-width: 8.5rem; display: flex; align-items: center; gap: .6rem; padding: .55rem .8rem; border-radius: .75rem; background: #fff; border: 1px solid var(--gray-200); text-decoration: none; color: var(--gray-700); }
        .today-stat:hover { border-color: var(--gray-300); box-shadow: 0 1px 4px rgb(0 0 0 / .06); }
        .dark .today-stat { background: var(--gray-900); border-color: rgb(255 255 255 / .1); color: var(--gray-300); }
        .today-stat svg { width: 1.25rem; height: 1.25rem; flex: none; }
        .today-stat b { font-size: 1.15rem; line-height: 1; font-variant-numeric: tabular-nums; color: var(--gray-950); }
        .dark .today-stat b { color: #fff; }
        .today-stat span { display: block; font-size: .72rem; line-height: 1.2; }
        .today-stat small { display: block; font-size: .68rem; color: var(--gray-500); white-space: nowrap; }
        .today-stat.c-success svg { color: var(--success-500); }
        .today-stat.c-warning svg { color: var(--warning-500); }
        .today-stat.c-warning { border-color: var(--warning-300); }
        .today-stat.c-danger svg { color: var(--danger-500); }
        .today-stat.c-danger { border-color: var(--danger-300); }
        .today-stat.c-primary svg { color: var(--primary-500); }
    </style>

    <nav class="today-strip" wire:poll.30s aria-label="Resumen del día">
        @foreach ($stats as $stat)
            <a href="{{ $stat['url'] }}" class="today-stat c-{{ $stat['color'] }}" title="{{ $stat['hint'] }}">
                <x-filament::icon :icon="$stat['icon']" />
                <div>
                    <span><b>{{ $stat['value'] }}</b> {{ $stat['label'] }}</span>
                    <small>{{ $stat['hint'] }}</small>
                </div>
            </a>
        @endforeach
    </nav>
</x-filament-widgets::widget>
