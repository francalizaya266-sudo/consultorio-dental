{{-- Dos enlaces con aspecto de selector: la vista elegida queda marcada y se recuerda en esta computadora. --}}
<x-filament-widgets::widget>
    <div class="dash-view" @if ($clinic !== []) wire:poll.30s @endif>
        <nav class="dash-view-switch" aria-label="Cómo ver el día">
            @foreach ($views as $view)
                <a href="{{ $view['url'] }}" @class(['is-active' => $view['active']]) @if ($view['active']) aria-current="page" @endif>{{ $view['label'] }}</a>
            @endforeach
        </nav>

        @if ($clinic !== [])
            <a href="{{ $clinicUrl }}" class="dash-view-clinic">
                <span>En la clínica:</span>
                @foreach ($clinic as $part)
                    <b @class(['is-alert' => $part['alert']])>{{ $part['text'] }}</b>@if (! $loop->last)<i aria-hidden="true">·</i>@endif
                @endforeach
            </a>
        @endif
    </div>
</x-filament-widgets::widget>
