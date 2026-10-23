@use('App\Support\Teeth')
@php
    $color = fn (string $state) => $state === 'good' ? '#2563eb' : '#dc2626';
@endphp

<div class="od-wrap">
    <style>
        .od-wrap { display: flex; flex-direction: column; gap: .9rem; }
        .od-toolbar { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
        .od-chip { border: 1px solid var(--gray-300); border-radius: 9999px; padding: .2rem .65rem; font-size: .78rem; background: #fff; color: var(--gray-700); cursor: pointer; line-height: 1.3; }
        .od-chip:hover { border-color: var(--primary-500); }
        .od-chip.is-active { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
        .dark .od-chip { background: var(--gray-900); color: var(--gray-200); border-color: rgb(255 255 255 / .15); }
        .od-options { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; padding: .55rem .75rem; border-radius: .6rem; background: var(--primary-50); font-size: .8rem; color: var(--gray-700); }
        .dark .od-options { background: rgb(255 255 255 / .05); color: var(--gray-200); }
        .od-state { display: inline-flex; border: 1px solid var(--gray-300); border-radius: .5rem; overflow: hidden; }
        .od-state button { padding: .2rem .6rem; font-size: .78rem; background: #fff; color: var(--gray-700); }
        .od-state button[disabled] { opacity: .35; cursor: not-allowed; }
        .od-state button.is-bad { background: #dc2626; color: #fff; }
        .od-state button.is-good { background: #2563eb; color: #fff; }
        .od-hint { font-weight: 600; color: var(--primary-700); }
        .od-scroll { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: .75rem; background: #fff; padding: .5rem; }
        .dark .od-scroll { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .od-legend { display: flex; flex-wrap: wrap; gap: .3rem 1rem; font-size: .75rem; color: var(--gray-600); }
        .od-legend i { display: inline-block; width: .7rem; height: .7rem; border-radius: .15rem; margin-right: .3rem; vertical-align: -1px; }
        .od-findings { width: 100%; font-size: .82rem; border-collapse: collapse; }
        .od-findings th { text-align: left; font-weight: 600; color: var(--gray-500); font-size: .72rem; padding: .3rem .5rem; border-bottom: 1px solid var(--gray-200); }
        .od-findings td { padding: .35rem .5rem; border-bottom: 1px solid var(--gray-100); color: var(--gray-800); }
        .dark .od-findings td { color: var(--gray-200); border-color: rgb(255 255 255 / .05); }
        .od-dot { display: inline-block; width: .6rem; height: .6rem; border-radius: 9999px; margin-right: .35rem; }
        .od-notes { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        .od-notes label { font-size: .8rem; font-weight: 600; color: var(--gray-700); display: block; margin-bottom: .25rem; }
        .od-notes textarea { width: 100%; min-height: 4rem; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .4rem .55rem; font-size: .85rem; background: #fff; }
        .dark .od-notes textarea { background: var(--gray-900); border-color: rgb(255 255 255 / .15); color: #fff; }
        .od-muted { font-size: .8rem; color: var(--gray-500); }
        .od-link { color: var(--primary-600); font-weight: 600; cursor: pointer; }
    </style>

    @if (! $odontogram)
        <p class="od-muted">Todavía no hay odontograma. Se crea solo al iniciar la primera atención.</p>
    @else
        <div class="od-toolbar" style="justify-content: space-between">
            <div class="od-legend">
                <span><i style="background:#dc2626"></i>Rojo: por tratar</span>
                <span><i style="background:#2563eb"></i>Azul: tratado o en buen estado</span>
            </div>

            <div class="od-toolbar">
                @if ($this->versions->count() > 1)
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:change="showVersion($event.target.value)" aria-label="Versión">
                            @foreach ($this->versions as $version)
                                <option value="{{ $version->id }}" @selected($version->id === $odontogram->id)>
                                    {{ $version->created_at->format('d/m/Y') }} · {{ $version->doctor?->name ?? 'Sin doctor' }} · {{ $version->type->getLabel() }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @elseif (! $canEdit)
                    <span class="od-muted">{{ $odontogram->type->getLabel() }} · {{ $odontogram->created_at->format('d/m/Y') }} · {{ $odontogram->doctor?->name }}</span>
                @endif

                @if ($canEdit)
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:change="setDentition($event.target.value)" aria-label="Dentición">
                            @foreach (\App\Enums\Dentition::cases() as $dentition)
                                <option value="{{ $dentition->value }}" @selected($dentition === $odontogram->dentition)>{{ $dentition->getLabel() }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
            </div>
        </div>

        @if ($canEdit)
            <div class="od-toolbar" aria-label="Hallazgos">
                @foreach ($frequent as $condition)
                    <button type="button" wire:click="selectTool(@js($tool === $condition->code ? null : $condition->code))" @class(['od-chip', 'is-active' => $tool === $condition->code])>{{ $condition->name }}</button>
                @endforeach
                <x-filament::input.wrapper style="max-width: 15rem">
                    <x-filament::input.select wire:change="selectTool($event.target.value || null)" aria-label="Otros hallazgos">
                        <option value="">Otros hallazgos…</option>
                        @foreach ($others as $condition)
                            <option value="{{ $condition->code }}" @selected($tool === $condition->code)>{{ $condition->name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($current)
                <div class="od-options">
                    <span class="od-state" role="group" aria-label="Estado">
                        <button type="button" wire:click="$set('state', 'bad')" @class(['is-bad' => $state === 'bad']) @disabled(! $current->allows_bad_state)>Por tratar</button>
                        <button type="button" wire:click="$set('state', 'good')" @class(['is-good' => $state === 'good']) @disabled(! $current->allows_good_state)>Tratado</button>
                    </span>

                    @if ($current->abbreviations)
                        <label>
                            Sigla
                            <select wire:model.live="abbreviation" style="margin-left: .3rem; border: 1px solid var(--gray-300); border-radius: .4rem; padding: .1rem .4rem; background: #fff">
                                <option value="">Sin sigla</option>
                                @foreach ($current->abbreviations as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <span class="od-hint">
                        @switch($current->applies_to)
                            @case('surface') Toca la superficie de la pieza. @break
                            @case('span') {{ $spanStart ? "Ahora toca la última pieza (desde la {$spanStart})." : 'Toca la primera pieza y luego la última.' }} @break
                            @default Toca la pieza.
                        @endswitch
                        Tocar de nuevo la quita.
                    </span>

                    <button type="button" class="od-link" wire:click="selectTool(null)">Listo</button>
                </div>
            @else
                <p class="od-muted">Elige un hallazgo y toca la pieza. Sin hallazgo elegido, al tocar una pieza ves lo que tiene.</p>
            @endif
        @endif

        <div class="od-scroll">
            <x-odontogram-drawing :odontogram="$odontogram" :rows="$rows" :teeth="$teeth" :spans="$spans" interactive :editing="$canEdit && $current" :selected-tooth="$selectedTooth" :span-start="$spanStart" />
        </div>

        <div>
            <div class="od-toolbar" style="justify-content: space-between; margin-bottom: .35rem">
                <strong style="font-size: .85rem">
                    Hallazgos{{ $selectedTooth ? " de la pieza {$selectedTooth}" : '' }} ({{ $findings->count() }})
                </strong>
                @if ($selectedTooth)
                    <button type="button" class="od-link" style="font-size: .8rem" wire:click="$set('selectedTooth', null)">Ver todos</button>
                @endif
            </div>

            @if ($findings->isEmpty())
                <p class="od-muted">{{ $selectedTooth ? 'Esta pieza no tiene marcas.' : 'Sin hallazgos marcados.' }}</p>
            @else
                <table class="od-findings">
                    <thead>
                        <tr><th>Pieza</th><th>Hallazgo</th><th>Estado</th>@if ($canEdit)<th></th>@endif</tr>
                    </thead>
                    <tbody>
                        @foreach ($findings as $entry)
                            <tr wire:key="f-{{ $entry->id }}">
                                <td>{{ $entry->tooth }}{{ $entry->to_tooth ? ' a '.$entry->to_tooth : '' }}{{ $entry->surface ? ' · '.Teeth::surfaceName($entry->surface) : '' }}</td>
                                <td>{{ $entry->condition->name }}{{ $entry->abbreviation ? " ({$entry->abbreviation})" : '' }}</td>
                                <td><span class="od-dot" style="background: {{ $color($entry->state->value) }}"></span>{{ $entry->state === \App\Enums\ToothState::Good ? 'Tratado' : 'Por tratar' }}</td>
                                @if ($canEdit)
                                    <td style="text-align: right">
                                        <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" label="Quitar" wire:click="remove({{ $entry->id }})" />
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($canEdit)
            <div class="od-notes">
                <div>
                    <label for="od-specs-{{ $odontogram->id }}">Especificaciones</label>
                    <textarea id="od-specs-{{ $odontogram->id }}" wire:model.blur="specifications" placeholder="Ej.: pieza 36 con restauración desadaptada"></textarea>
                </div>
                <div>
                    <label for="od-obs-{{ $odontogram->id }}">Observaciones</label>
                    <textarea id="od-obs-{{ $odontogram->id }}" wire:model.blur="observations"></textarea>
                </div>
            </div>
        @elseif ($odontogram->specifications || $odontogram->observations)
            <div class="od-notes">
                @if ($odontogram->specifications)
                    <div><label>Especificaciones</label><p style="font-size: .85rem; white-space: pre-line">{{ $odontogram->specifications }}</p></div>
                @endif
                @if ($odontogram->observations)
                    <div><label>Observaciones</label><p style="font-size: .85rem; white-space: pre-line">{{ $odontogram->observations }}</p></div>
                @endif
            </div>
        @endif
    @endif
</div>
