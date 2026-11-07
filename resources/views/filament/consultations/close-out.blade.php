{{-- Al terminar la atención: cobrar (o avisar a recepción) y la próxima sesión, cada cosa con su botón. --}}
<div class="closeout">
    @foreach (['payment' => ['heroicon-o-banknotes', 'Cobro'], 'next' => ['heroicon-o-calendar-days', 'Próxima cita']] as $key => [$icon, $kicker])
        @php($row = $closeOut[$key])
        <div @class(['pt-step', 'closeout-row', 'is-done' => $row['done']]) data-closeout="{{ $key }}">
            <x-filament::icon :icon="$row['done'] ? 'heroicon-o-check-circle' : $icon" class="pt-step-icon" />
            <div class="pt-step-text">
                <span class="pt-step-kicker">{{ $kicker }}</span>
                <strong class="pt-step-label">{{ $row['label'] }}</strong>
                <span class="pt-step-detail">{{ $row['detail'] }}</span>
            </div>
            @if (($row['action'] ?? null) !== null)
                <x-filament::button size="sm" class="pt-step-button" wire:click="mountAction('{{ $row['action'] }}')">{{ $row['button'] }}</x-filament::button>
            @elseif (($row['url'] ?? null) !== null)
                <x-filament::button size="sm" class="pt-step-button" tag="a" :href="$row['url']">Agendar</x-filament::button>
            @endif
        </div>
    @endforeach
</div>
