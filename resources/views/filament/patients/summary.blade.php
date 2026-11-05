{{-- Bajo el nombre: lo que hay que saber del paciente y cómo escribirle, sin abrir nada. --}}
<div class="pt-summary">
    @if ($chips !== [])
        <ul class="pt-chips" aria-label="Resumen del paciente">
            @foreach ($chips as $chip)
                <li>
                    @if ($chip['url'])
                        <a href="{{ $chip['url'] }}" class="pt-chip is-{{ $chip['color'] }}">
                            <x-filament::icon :icon="$chip['icon']" class="pt-chip-icon" />
                            {{ $chip['label'] }}
                        </a>
                    @else
                        <span class="pt-chip is-{{ $chip['color'] }}">
                            <x-filament::icon :icon="$chip['icon']" class="pt-chip-icon" />
                            {{ $chip['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($contact)
        <div class="pt-contact">
            <x-filament::link :href="$contact['whatsapp']" target="_blank" icon="heroicon-m-chat-bubble-left-right" color="success" size="sm">WhatsApp</x-filament::link>
            <x-filament::link :href="$contact['call']" icon="heroicon-m-phone" color="gray" size="sm">Llamar</x-filament::link>
        </div>
    @endif
</div>
