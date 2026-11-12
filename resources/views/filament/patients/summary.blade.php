{{-- Bajo el nombre: lo que toca hacer con el paciente, lo que hay que saber de él y cómo escribirle, sin abrir nada. --}}
<div class="pt-summary">
    @if ($step)
        <div class="pt-step" data-step="{{ $step['key'] }}">
            <x-filament::icon :icon="$step['icon']" class="pt-step-icon" />
            <div class="pt-step-text">
                <span class="pt-step-kicker">Siguiente paso</span>
                <strong class="pt-step-label">{{ $step['label'] }}</strong>
                @if ($step['detail'])
                    <span class="pt-step-detail">{{ $step['detail'] }}</span>
                @endif
            </div>
            @if ($step['button'])
                @if ($step['action'])
                    <x-filament::button size="sm" class="pt-step-button" wire:click="mountAction('{{ $step['action']['name'] }}', {{ \Illuminate\Support\Js::from($step['action']['arguments']) }})">{{ $step['button'] }}</x-filament::button>
                @else
                    <x-filament::button size="sm" class="pt-step-button" tag="a" :href="$step['url']">{{ $step['button'] }}</x-filament::button>
                @endif
            @endif
        </div>
    @endif

    @if ($chips !== [])
        <ul class="pt-chips" aria-label="Resumen del paciente">
            @foreach ($chips as $chip)
                <li>
                    @if ($chip['action'])
                        <button type="button" class="pt-chip is-{{ $chip['color'] }}" wire:click="mountAction('{{ $chip['action']['name'] }}', {{ \Illuminate\Support\Js::from($chip['action']['arguments']) }})">
                            <x-filament::icon :icon="$chip['icon']" class="pt-chip-icon" />
                            {{ $chip['label'] }}
                        </button>
                    @elseif ($chip['url'])
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
            {{-- En la página de la cita no va: allí WhatsApp es el botón de arriba, con el aviso de esa cita ya escrito. --}}
            @if ($contact['whatsapp'])
                <x-filament::link :href="$contact['whatsapp']" target="_blank" icon="heroicon-m-chat-bubble-left-right" color="success" size="sm">WhatsApp</x-filament::link>
            @endif
            <x-filament::link :href="$contact['call']" icon="heroicon-m-phone" color="gray" size="sm">Llamar</x-filament::link>
        </div>
    @endif
</div>
