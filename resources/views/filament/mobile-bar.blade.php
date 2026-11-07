{{-- Solo en el celular y la tableta (donde el menú se esconde): lo más usado, fijo abajo. --}}
@if (count($items) > 1)
    <nav class="mobile-bar" aria-label="Accesos rápidos" style="--items: {{ count($items) }}">
        @foreach ($items as $item)
            <a href="{{ $item['url'] }}" @class(['mobile-bar-item', 'is-active' => $item['active'], 'is-primary' => $item['primary']]) @if ($item['active']) aria-current="page" @endif @if ($item['badge']) aria-label="{{ $item['label'] }}, {{ $item['badge'] }} por enviar" @endif>
                <span class="mobile-bar-glyph">
                    <x-filament::icon :icon="$item['icon']" class="mobile-bar-icon" />
                    @if ($item['badge'])
                        <span class="mobile-bar-badge" aria-hidden="true">{{ $item['badge'] }}</span>
                    @endif
                </span>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
@endif
