{{-- Cuántos lleva contados, al lado de Guardar: se actualiza mientras escribe, sin ir al servidor. --}}
<p
    class="stock-count-progress"
    x-data="{ total: {{ $total }} }"
    x-text="'Llevas ' + Object.values($wire.data?.counted ?? {}).filter((value) => value !== null && value !== '').length + ' de ' + total"
>Llevas 0 de {{ $total }}</p>
