{{-- Solo en el celular y la tableta (donde el menú se esconde): lo más usado, fijo abajo. --}}
@if (count($items) > 1)
    <nav class="mobile-bar" aria-label="Accesos rápidos" style="--items: {{ count($items) }}">
        @foreach ($items as $item)
            <a href="{{ $item['url'] }}" @class(['mobile-bar-item', 'is-active' => $item['active'], 'is-primary' => $item['primary']]) @if ($item['active']) aria-current="page" @endif>
                <x-filament::icon :icon="$item['icon']" class="mobile-bar-icon" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
@endif
