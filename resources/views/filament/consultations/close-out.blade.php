@use('Illuminate\Support\Str')
{{-- Al terminar la atención: el presupuesto por aceptar, cobrar (o avisar a recepción) y la próxima sesión, cada cosa con su botón. --}}
<div class="closeout">
    @foreach (['plan' => ['heroicon-o-clipboard-document-list', 'Plan de tratamiento'], 'payment' => ['heroicon-o-banknotes', 'Cobro'], 'next' => ['heroicon-o-calendar-days', 'Próxima cita']] as $key => [$icon, $kicker])
        @php($row = $closeOut[$key])
        @continue($row === null)
        <div @class(['pt-step', 'closeout-row', 'is-done' => $row['done']]) data-closeout="{{ $key }}">
            <x-filament::icon :icon="$row['done'] ? 'heroicon-o-check-circle' : $icon" class="pt-step-icon" />
            <div class="pt-step-text">
                <span class="pt-step-kicker">{{ $kicker }}</span>
                <strong class="pt-step-label">{{ $row['label'] }}</strong>
                <span class="pt-step-detail">{{ $row['detail'] }}</span>
                @if (($row['options'] ?? []) !== [])
                    <span class="pt-step-detail closeout-options">Formas de pago, se elige al firmar: {{ collect($row['options'])->map(fn ($option) => Str::lcfirst(rtrim($option, '.')))->implode(' · ') }}</span>
                @endif
            </div>
            @if (($row['links'] ?? []) !== [] || ($row['action'] ?? null) !== null || ($row['url'] ?? null) !== null)
                <div class="closeout-actions">
                    @foreach ($row['links'] ?? [] as $link)
                        @if (isset($link['url']))
                            <x-filament::link tag="a" :href="$link['url']" target="_blank" size="sm" color="gray">{{ $link['label'] }}</x-filament::link>
                        @else
                            <x-filament::link tag="button" wire:click="mountAction('{{ $link['action'] }}')" size="sm" color="gray">{{ $link['label'] }}</x-filament::link>
                        @endif
                    @endforeach
                    @if (($row['action'] ?? null) !== null)
                        <x-filament::button size="sm" class="pt-step-button" wire:click="mountAction('{{ $row['action'] }}')">{{ $row['button'] }}</x-filament::button>
                    @elseif (($row['url'] ?? null) !== null)
                        <x-filament::button size="sm" class="pt-step-button" tag="a" :href="$row['url']">Agendar</x-filament::button>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
