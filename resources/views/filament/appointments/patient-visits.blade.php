{{-- Las otras citas del paciente alrededor de esta: un toque lleva a la anterior o a la siguiente. --}}
<div class="ap-visits">
    @foreach ($rows as $row)
        @php($tag = $row['url'] ? 'a' : 'div')
        <{{ $tag }} @if ($row['url']) href="{{ $row['url'] }}" @endif @class(['ap-visit', 'is-current' => $row['current']]) @if ($row['current']) aria-current="page" @endif>
            <span class="ap-visit-text">
                <b>{{ $row['title'] }}</b>
                <small>{{ $row['when'] }}</small>
            </span>
            @if ($row['current'])
                <span class="ap-visit-here">Esta cita</span>
            @else
                <x-filament::badge :color="$row['color']" size="sm">{{ $row['status'] }}</x-filament::badge>
            @endif
        </{{ $tag }}>
    @endforeach

    @if ($total === 1)
        <p class="ap-visits-note">Es su única cita hasta ahora.</p>
    @endif

    @if ($profile_url || $new_url)
        <div class="ap-visits-links">
            @if ($profile_url)
                <x-filament::link :href="$profile_url" icon="heroicon-m-user" size="sm">Ver las {{ $total }} en su ficha</x-filament::link>
            @endif
            @if ($new_url)
                <x-filament::link :href="$new_url" icon="heroicon-m-calendar-days" size="sm" color="gray">Nueva cita</x-filament::link>
            @endif
        </div>
    @endif
</div>
