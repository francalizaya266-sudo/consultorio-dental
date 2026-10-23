@use('App\Livewire\OdontogramChart')
@use('App\Services\Clinical\OdontogramService')
@use('App\Support\Money')
@use('App\Support\OdontogramAbbreviations')
@use('App\Support\Teeth')
@php
    $color = fn (string $state) => $state === 'good' ? '#2563eb' : '#dc2626';
    // Pieza ampliada: las cinco caras en un cuadro de 100 × 100, con dónde va cada letra.
    $zoomFaces = [
        'top' => ['0,0 100,0 70,30 30,30', 50, 15],
        'right' => ['100,0 100,100 70,70 70,30', 85, 50],
        'bottom' => ['100,100 0,100 30,70 70,70', 50, 85],
        'left' => ['0,100 0,0 30,30 30,70', 15, 50],
        'center' => ['30,30 70,30 70,70 30,70', 50, 50],
    ];
@endphp

<div class="od-wrap" @if ($canEdit) x-data="odontogramEditor(@js(['catalog' => $catalog, 'frequent' => $frequent->pluck('code'), 'favorites' => $this->favorites->pluck('code'), 'surfaceNames' => $surfaceNames]))" @endif>
    <style>
        .od-wrap { display: flex; flex-direction: column; gap: .9rem; }
        .od-toolbar { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
        .od-chip { border: 1px solid var(--gray-300); border-radius: 9999px; padding: .2rem .65rem; font-size: .78rem; background: #fff; color: var(--gray-700); cursor: pointer; line-height: 1.3; }
        .od-chip:hover { border-color: var(--primary-500); }
        .od-chip.is-active { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
        .od-chip.is-suggested { border-style: dashed; }
        .dark .od-chip { background: var(--gray-900); color: var(--gray-200); border-color: rgb(255 255 255 / .15); }
        .od-options { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; padding: .55rem .75rem; border-radius: .6rem; background: var(--primary-50); font-size: .8rem; color: var(--gray-700); }
        .dark .od-options { background: rgb(255 255 255 / .05); color: var(--gray-200); }
        .od-state { display: inline-flex; border: 1px solid var(--gray-300); border-radius: .5rem; overflow: hidden; }
        .od-state button { padding: .2rem .6rem; font-size: .78rem; background: #fff; color: var(--gray-700); }
        .od-state button[disabled] { opacity: .35; cursor: not-allowed; }
        .od-state button.is-bad { background: #dc2626; color: #fff; }
        .od-state button.is-good { background: #2563eb; color: #fff; }
        .od-hint { font-weight: 600; color: var(--primary-700); }
        .od-scroll { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: .75rem; background: #fff; padding: .5rem; scroll-margin-top: 5rem; }
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
        .od-chip-sm { padding: .1rem .5rem; font-size: .74rem; font-weight: 600; }
        .od-siglas { display: inline-flex; flex-wrap: wrap; gap: .3rem; align-items: center; }
        .od-sigla span { font-weight: 400; margin-left: .25rem; opacity: .8; }
        .od-treated { color: var(--primary-600); font-weight: 600; font-size: .78rem; margin-right: .5rem; cursor: pointer; white-space: nowrap; }
        /* El dibujo usa el ancho disponible (los dientes crecen hasta 1,5 veces) y en el celular se desliza. */
        .od-scroll .od-svg { width: 100%; height: auto; max-width: 69rem; min-width: 38rem; }
        .od-svg [data-busy] .od-surface.is-none, .od-svg [data-busy] .od-box { fill: var(--primary-100); }
        .od-svg [data-span-start] .od-surface.is-none, .od-svg [data-span-start] .od-box { fill: #fef3c7; }
        .od-svg [data-zoomed] .od-box { fill: var(--primary-100); stroke: var(--primary-500); }
        @media (prefers-reduced-motion: no-preference) {
            .od-svg [data-busy] { animation: od-busy 1s ease-in-out infinite; }
            @keyframes od-busy { 50% { opacity: .6; } }
        }
        /* Buscador de los demás hallazgos. */
        .od-search { position: relative; }
        .od-search input { width: 15rem; max-width: 100%; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .3rem .6rem; font-size: .8rem; background: #fff; color: var(--gray-800); }
        .od-search-list { position: absolute; z-index: 30; top: calc(100% + .25rem); left: 0; width: 17rem; max-height: 16rem; overflow-y: auto; background: #fff; border: 1px solid var(--gray-200); border-radius: .6rem; box-shadow: 0 8px 24px rgb(0 0 0 / .12); padding: .25rem; }
        .od-search-list button { display: block; width: 100%; text-align: left; padding: .4rem .6rem; border-radius: .4rem; font-size: .8rem; color: var(--gray-800); }
        .od-search-list button:hover, .od-search-list button.is-first { background: var(--primary-50); }
        .od-search-list small { color: var(--gray-500); margin-left: .3rem; }
        .dark .od-search input, .dark .od-search-list { background: var(--gray-900); border-color: rgb(255 255 255 / .15); color: var(--gray-200); }
        .dark .od-search-list button { color: var(--gray-200); }
        .dark .od-search-list button:hover, .dark .od-search-list button.is-first { background: rgb(255 255 255 / .06); }
        /* Escribir o dictar. */
        .od-dictation { display: flex; flex-direction: column; gap: .5rem; padding: .65rem .75rem; border: 1px dashed var(--gray-300); border-radius: .6rem; }
        .od-dictation-row { display: flex; gap: .5rem; flex-wrap: wrap; }
        .od-dictation input { flex: 1 1 18rem; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .45rem .65rem; font-size: .88rem; background: #fff; color: var(--gray-800); }
        .od-dictation ul { display: flex; flex-direction: column; gap: .2rem; font-size: .8rem; }
        .od-dictation .is-ok { color: var(--gray-800); }
        .od-dictation .is-error { color: #b45309; }
        .dark .od-dictation { border-color: rgb(255 255 255 / .15); }
        .dark .od-dictation input { background: var(--gray-900); border-color: rgb(255 255 255 / .15); color: #fff; }
        .dark .od-dictation .is-ok { color: var(--gray-200); }
        .dark .od-dictation .is-error { color: #fbbf24; }
        /* Pieza ampliada: para marcar caras con el dedo en la tablet. */
        .od-zoom { position: fixed; z-index: 40; left: 50%; bottom: 1rem; transform: translateX(-50%); width: min(21rem, calc(100vw - 2rem)); background: #fff; border: 1px solid var(--gray-200); border-radius: 1rem; box-shadow: 0 12px 32px rgb(0 0 0 / .2); padding: .75rem; display: flex; flex-direction: column; gap: .5rem; }
        .od-zoom-head { display: flex; align-items: center; gap: .5rem; }
        .od-zoom-head strong { flex: 1; text-align: center; font-size: 1rem; }
        .od-zoom-nav { min-width: 2.75rem; min-height: 2.75rem; border: 1px solid var(--gray-300); border-radius: .6rem; font-size: 1.2rem; color: var(--gray-700); }
        .od-zoom-svg { width: 100%; max-width: 11.5rem; margin: 0 auto; background: #fff; }
        @media (min-width: 1024px) { .od-zoom { left: auto; right: 1.5rem; transform: none; } }
        .od-zoom-svg .od-surface { stroke-width: .8; }
        .od-zoom-svg text { text-anchor: middle; dominant-baseline: middle; fill: var(--gray-500); pointer-events: none; }
        .od-zoom-svg .od-zoom-letter { font-size: 11px; font-weight: 700; }
        .od-zoom-svg .od-zoom-name { font-size: 5.5px; }
        .od-zoom-svg text.is-on { fill: #fff; }
        .od-zoom p { font-size: .8rem; text-align: center; color: var(--gray-600); }
        .dark .od-zoom { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .dark .od-zoom-nav { border-color: rgb(255 255 255 / .15); color: var(--gray-200); }
        .dark .od-zoom p { color: var(--gray-300); }
        /* Sugerencias para el diagnóstico y el plan. */
        .od-plan { border: 1px solid var(--gray-200); border-radius: .75rem; padding: .75rem; display: flex; flex-direction: column; gap: .6rem; }
        .od-plan-head { display: flex; flex-wrap: wrap; gap: .25rem .75rem; align-items: baseline; font-size: .85rem; }
        .od-plan-row { display: flex; gap: .6rem; align-items: flex-start; padding: .45rem .35rem; border-radius: .5rem; cursor: pointer; }
        .od-plan-row:hover { background: var(--gray-50); }
        .od-plan-row input { margin-top: .2rem; width: 1rem; height: 1rem; accent-color: var(--primary-600); flex: none; }
        .od-plan-row.is-done { opacity: .55; cursor: default; }
        .od-plan-text { flex: 1; display: flex; flex-direction: column; gap: .1rem; min-width: 0; }
        .od-plan-main { font-size: .85rem; color: var(--gray-800); }
        .od-plan-sub { font-size: .75rem; color: var(--gray-500); }
        .od-plan-side { display: flex; flex-direction: column; align-items: flex-end; gap: .15rem; font-size: .8rem; white-space: nowrap; }
        .od-plan-price { font-weight: 600; color: var(--gray-800); }
        .od-tag { font-size: .7rem; padding: .05rem .45rem; border-radius: 9999px; background: var(--gray-100); color: var(--gray-600); }
        .od-plan-foot { display: flex; flex-wrap: wrap; gap: .5rem 1rem; justify-content: space-between; align-items: center; border-top: 1px solid var(--gray-100); padding-top: .6rem; font-size: .85rem; }
        .dark .od-plan { border-color: rgb(255 255 255 / .1); }
        .dark .od-plan-row:hover { background: rgb(255 255 255 / .04); }
        .dark .od-plan-main, .dark .od-plan-price { color: var(--gray-200); }
        .dark .od-plan-sub { color: var(--gray-400); }
        .dark .od-tag { background: rgb(255 255 255 / .08); color: var(--gray-300); }
        .dark .od-plan-foot { border-color: rgb(255 255 255 / .06); }
        /* En pantallas táctiles, todo lo que se toca mide al menos 44 px. */
        @media (pointer: coarse) {
            .od-chip, .od-chip-sm { min-height: 2.75rem; padding: .45rem .9rem; font-size: .85rem; }
            .od-state button { min-height: 2.75rem; padding: .45rem .9rem; font-size: .85rem; }
            .od-treated { min-height: 2.75rem; padding: 0 .5rem; }
            .od-link { display: inline-flex; align-items: center; min-height: 2.75rem; }
            .od-search input { min-height: 2.75rem; font-size: .9rem; }
            .od-plan-row input { width: 1.4rem; height: 1.4rem; }
        }
        /* Modo oscuro: el odontograma sigue siendo una hoja blanca, como impreso, para que el rojo y el azul se lean igual. */
        .dark .od-scroll { background: #fff; border-color: rgb(255 255 255 / .1); }
        .dark .od-legend, .dark .od-notes label { color: var(--gray-300); }
        .dark .od-hint, .dark .od-link, .dark .od-treated { color: var(--primary-400); }
        .dark .od-state { border-color: rgb(255 255 255 / .15); }
        .dark .od-state button:not(.is-bad):not(.is-good) { background: var(--gray-800); color: var(--gray-200); }
        .dark .od-chip.is-active { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
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
            {{-- Elegir hallazgo, color y sigla no espera al servidor: lo maneja el navegador. --}}
            <div wire:ignore style="display: flex; flex-direction: column; gap: .9rem">
                <div class="od-toolbar" aria-label="Hallazgos">
                    @foreach ($frequent as $condition)
                        <button type="button" class="od-chip" x-bind:class="{ 'is-active': tool === @js($condition->code) }" x-on:click="select(@js($condition->code))">{{ $condition->name }}</button>
                    @endforeach
                    @foreach ($this->favorites as $condition)
                        <button type="button" class="od-chip is-suggested" title="Lo marcas seguido" x-bind:class="{ 'is-active': tool === @js($condition->code) }" x-on:click="select(@js($condition->code))">{{ $condition->name }}</button>
                    @endforeach

                    <template x-if="tool && ! frequent.includes(tool) && ! favorites.includes(tool)">
                        <button type="button" class="od-chip is-active" x-on:click="select(null)" x-text="current.name"></button>
                    </template>

                    <div class="od-search" x-on:click.outside="searching = false">
                        <input type="search" x-model="query" x-on:focus="searching = true" x-on:input="searching = true" x-on:keydown.enter.prevent="pick(matches[0])" x-on:keydown.escape="query = ''; searching = false" placeholder="Buscar otro hallazgo…" aria-label="Buscar otro hallazgo" autocomplete="off">
                        <div class="od-search-list" x-show="searching" x-cloak>
                            <template x-for="(code, index) in matches" x-bind:key="code">
                                <button type="button" x-on:click="pick(code)" x-bind:class="{ 'is-first': index === 0 && query }">
                                    <span x-text="catalog[code].name"></span><small x-text="catalog[code].abbreviations.join(' ')"></small>
                                </button>
                            </template>
                            <p class="od-muted" style="padding: .4rem .6rem" x-show="! matches.length">No hay un hallazgo con ese nombre.</p>
                        </div>
                    </div>

                    <button type="button" class="od-chip" x-bind:class="{ 'is-active': writing }" x-on:click="toggleWriting()">Escribir o dictar</button>
                </div>

                <div class="od-options" x-show="tool" x-cloak>
                    <span class="od-state" role="group" aria-label="Estado">
                        <button type="button" x-on:click="setState('bad')" x-bind:class="{ 'is-bad': state === 'bad' }" x-bind:disabled="! current?.bad">Por tratar</button>
                        <button type="button" x-on:click="setState('good')" x-bind:class="{ 'is-good': state === 'good' }" x-bind:disabled="! current?.good">Tratado</button>
                    </span>

                    <span class="od-siglas" x-show="current?.abbreviations.length" role="group" aria-label="Sigla">
                        Sigla
                        <template x-for="option in current?.abbreviations ?? []" x-bind:key="option">
                            <button type="button" class="od-chip od-chip-sm od-sigla" x-bind:class="{ 'is-active': abbreviation === option }" x-on:click="setAbbreviation(option)" x-bind:title="meaning(option) ?? option">
                                <b x-text="option"></b><span x-show="word(option)" x-text="word(option)"></span>
                            </button>
                        </template>
                    </span>

                    <button type="button" class="od-chip od-chip-sm" x-show="current?.appliesTo === 'surface'" x-bind:class="{ 'is-active': lupa }" x-on:click="toggleLupa()" title="Al tocar una pieza, se abre en grande para marcar sus caras con el dedo">Lupa</button>

                    <span class="od-hint" x-text="hint"></span>

                    <button type="button" class="od-link" x-on:click="select(null)">Listo</button>
                </div>

                <p class="od-muted" x-show="! tool">Elige un hallazgo y toca la pieza. Sin hallazgo elegido, al tocar una pieza ves lo que tiene.</p>

                <div class="od-zoom" x-show="zoom !== null" x-cloak role="dialog" aria-label="Pieza ampliada">
                    <div class="od-zoom-head">
                        <button type="button" class="od-zoom-nav" x-on:click="zoomStep(-1)" aria-label="Pieza de la izquierda">‹</button>
                        <strong x-text="`Pieza ${zoom}`"></strong>
                        <button type="button" class="od-zoom-nav" x-on:click="zoomStep(1)" aria-label="Pieza de la derecha">›</button>
                    </div>
                    <svg class="od-svg od-zoom-svg is-editing" viewBox="-2 -2 104 104" role="group" aria-label="Caras de la pieza">
                        @foreach ($zoomFaces as $zone => [$points, $x, $y])
                            <polygon points="{{ $points }}" class="od-surface od-hit" x-bind:class="`is-${face('{{ $zone }}').state}`" x-on:click="tap(zoom, face('{{ $zone }}').surface)"></polygon>
                            <text x="{{ $x }}" y="{{ $y - 3 }}" class="od-zoom-letter" x-bind:class="{ 'is-on': face('{{ $zone }}').state !== 'none' }" x-text="face('{{ $zone }}').surface"></text>
                            <text x="{{ $x }}" y="{{ $y + 7 }}" class="od-zoom-name" x-bind:class="{ 'is-on': face('{{ $zone }}').state !== 'none' }" x-text="surfaceNames[face('{{ $zone }}').surface] ?? ''"></text>
                        @endforeach
                    </svg>
                    <p x-text="zoomHint"></p>
                    <button type="button" class="od-link" style="align-self: center" x-on:click="zoom = null; paint()">Cerrar</button>
                </div>
            </div>

            <div class="od-dictation" x-show="writing" x-cloak>
                <div class="od-dictation-row">
                    <input type="text" x-ref="dictation" wire:model.live.debounce.600ms="dictation" x-on:keydown.enter.prevent="$wire.addDictated($event.target.value)" placeholder="Ej.: 36 O CD, 46 MO resina, 18 ausente, puente 14 a 16" aria-label="Hallazgos escritos o dictados" autocomplete="off">
                    <x-filament::button size="sm" x-on:click="$wire.addDictated($refs.dictation.value)" :disabled="collect($this->dictated)->every(fn ($finding) => $finding['error'])">
                        Agregar
                    </x-filament::button>
                </div>
                @if ($this->dictated)
                    <ul>
                        @foreach ($this->dictated as $finding)
                            @if ($finding['error'])
                                <li class="is-error">⚠ «{{ $finding['text'] }}»: {{ $finding['error'] }}</li>
                            @else
                                <li class="is-ok">✓ {{ $finding['summary'] }}</li>
                            @endif
                        @endforeach
                    </ul>
                @endif
                <p class="od-muted">Para dictar, toca el micrófono del teclado (en Windows, tecla Windows + H). Revisa lo que entendí antes de agregar.</p>
            </div>
        @endif

        <div class="od-scroll">
            <x-odontogram-drawing :odontogram="$odontogram" :rows="$rows" :teeth="$teeth" :spans="$spans" interactive :editing="$canEdit" :selected-tooth="$selectedTooth" />
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
                                <td title="{{ OdontogramAbbreviations::full($entry->condition->code, $entry->abbreviation) }}">{{ OdontogramChart::findingName($entry) }}</td>
                                <td><span class="od-dot" style="background: {{ $color($entry->state->value) }}"></span>{{ $entry->state === \App\Enums\ToothState::Good ? 'Tratado' : 'Por tratar' }}</td>
                                @if ($canEdit)
                                    <td>
                                        <div style="display: flex; justify-content: flex-end; align-items: center">
                                            @if (OdontogramService::canMarkTreated($entry))
                                                <button type="button" class="od-treated" x-on:click="treat({{ $entry->id }}, @js($entry->condition->code))">✓ Tratado</button>
                                            @endif
                                            <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" label="Quitar" wire:click="remove({{ $entry->id }})" />
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($suggestions !== [])
            <div class="od-plan">
                <div class="od-plan-head">
                    <strong>Para el diagnóstico y el plan</strong>
                    <span class="od-muted">Sale de lo marcado en el odontograma. Quita lo que no va y agrégalo con un clic.</span>
                </div>

                <div>
                    @foreach ($suggestions as $row)
                        @php $price = $row['treatment'] && ! $row['planned'] ? (float) $row['treatment']->price_from : 0; @endphp
                        <label wire:key="s-{{ $odontogram->id }}-{{ $row['key'] }}" @class(['od-plan-row', 'is-done' => $row['done']])>
                            <input type="checkbox" value="{{ $row['key'] }}" data-suggestion data-price="{{ $price }}" @checked($row['suggested'] || $row['done']) @disabled($row['done']) x-on:change="recount()">
                            <span class="od-plan-text">
                                <span class="od-plan-main"><b>{{ $row['tooth'] }}{{ $row['surfaces'] ? ' · '.$row['surfaces'] : '' }}</b> {{ $row['treatment']?->name ?? $row['diagnoses']->first()?->description }}</span>
                                <span class="od-plan-sub">{{ implode(', ', $row['findings']) }}@foreach ($row['diagnoses'] as $code) · {{ $code->code }} {{ $code->description }}@endforeach</span>
                            </span>
                            <span class="od-plan-side">
                                @if ($row['done'])
                                    <span class="od-tag">Ya agregado</span>
                                @elseif ($row['planned'])
                                    <span class="od-tag">Ya está en el plan</span>
                                @elseif ($row['optional'])
                                    <span class="od-tag">Opcional</span>
                                @endif
                                @if ($row['treatment'] && ! $row['planned'])
                                    <span class="od-plan-price">{{ $price > 0 ? Money::format($price) : 'Sin precio' }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="od-plan-foot">
                    <span>Para el plan: <strong x-text="money(planTotal)"></strong></span>
                    <x-filament::button size="sm" icon="heroicon-m-plus" x-on:click="applySuggestions()" x-bind:disabled="! picked">
                        Agregar al diagnóstico y al plan
                    </x-filament::button>
                </div>
            </div>
        @endif

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
    @if ($canEdit)
        <script>
            (() => {
                /**
                 * Editor del odontograma. Elegir hallazgo, color y sigla no pregunta al servidor, y cada toque
                 * se pinta al instante: al servidor va solo la marca. Mientras no responde, cada vez que el
                 * dibujo se vuelve a armar se repinta lo pendiente; al responder, manda lo que guardó él.
                 * En la tablet, la lupa abre la pieza en grande para marcar sus caras con el dedo.
                 */
                const plain = (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
                const remembered = (key, fallback) => {
                    try {
                        return localStorage.getItem(key) ?? fallback;
                    } catch (error) {
                        return fallback;
                    }
                };
                const remember = (key, value) => {
                    try {
                        localStorage.setItem(key, value);
                    } catch (error) {}
                };

                const register = () => Alpine.data('odontogramEditor', ({ catalog, frequent, favorites, surfaceNames }) => ({
                    catalog,
                    frequent,
                    favorites,
                    surfaceNames,
                    tool: null,
                    state: 'bad',
                    abbreviation: null,
                    spanStart: null,
                    // Color elegido por hallazgo mientras dura la página (la sigla se recuerda también después).
                    states: {},
                    pending: {},
                    query: '',
                    searching: false,
                    writing: remembered('odontograma.escribir', '0') === '1',
                    lupa: remembered('odontograma.lupa', window.matchMedia('(pointer: coarse)').matches ? '1' : '0') === '1',
                    zoom: null,
                    faces: {},
                    planTotal: 0,
                    picked: 0,
                    stopListening: null,

                    init() {
                        this.stopListening = Livewire.hook('morphed', ({ component }) => {
                            if (component.id === this.$wire.$id) {
                                this.paint();
                            }
                        });
                        this.$nextTick(() => this.paint());
                    },

                    destroy() {
                        this.stopListening?.();
                    },

                    get current() {
                        return this.tool ? this.catalog[this.tool] : null;
                    },

                    get hint() {
                        const meaning = this.abbreviation ? `${this.abbreviation} = ${this.meaning(this.abbreviation)}. ` : '';
                        const where = {
                            surface: this.lupa ? 'Toca la pieza para verla en grande.' : 'Toca la superficie de la pieza.',
                            span: this.spanStart === null ? 'Toca la primera pieza y luego la última.' : `Ahora toca la última pieza (desde la ${this.spanStart}).`,
                        }[this.current?.appliesTo] ?? 'Toca la pieza.';

                        return `${meaning}${where} Tocar de nuevo la quita.`;
                    },

                    get zoomHint() {
                        if (! this.current) {
                            return '';
                        }

                        const sigla = this.abbreviation ? ` ${this.abbreviation} (${this.word(this.abbreviation) ?? this.meaning(this.abbreviation)})` : '';

                        return `${this.current.name}${sigla}, ${this.state === 'good' ? 'tratado' : 'por tratar'}: toca una cara para marcarla o quitarla.`;
                    },

                    get matches() {
                        const query = plain(this.query);
                        const codes = Object.keys(this.catalog);

                        if (! query) {
                            return codes.filter((code) => ! this.frequent.includes(code) && ! this.favorites.includes(code));
                        }

                        return codes.filter((code) => {
                            const condition = this.catalog[code];
                            const words = [condition.name, ...condition.abbreviations, ...Object.values(condition.meanings).flat()].map(plain);

                            return words.some((word) => word.includes(query) || word.split(' ').some((part) => part.startsWith(query)));
                        }).slice(0, 8);
                    },

                    meaning(option) {
                        return this.current?.meanings[option]?.[1] ?? null;
                    },

                    word(option) {
                        const word = this.current?.meanings[option]?.[0];

                        return word && plain(word) !== plain(option) ? word : null;
                    },

                    select(code) {
                        const condition = code && code !== this.tool ? this.catalog[code] : null;

                        this.tool = condition ? code : null;
                        // Lo que se puede tener sano o tratado (restauración, corona…) empieza en azul; lo demás, en rojo.
                        this.state = ! condition ? 'bad' : (condition.good && condition.bad ? (this.states[code] ?? 'good') : (condition.good ? 'good' : 'bad'));
                        this.abbreviation = condition ? this.savedAbbreviation(code) : null;
                        this.spanStart = null;

                        if (! condition || condition.appliesTo !== 'surface') {
                            this.zoom = null;
                        }

                        this.paint();
                    },

                    pick(code) {
                        if (code) {
                            this.select(code === this.tool ? null : code);
                        }

                        this.query = '';
                        this.searching = false;
                    },

                    setState(state) {
                        if (this.current?.[state]) {
                            this.state = state;
                            this.states[this.tool] = state;
                        }
                    },

                    setAbbreviation(option) {
                        this.abbreviation = this.abbreviation === option ? null : option;
                        remember(`odontograma.sigla.${this.tool}`, this.abbreviation ?? '');
                    },

                    savedAbbreviation(code) {
                        const saved = remembered(`odontograma.sigla.${code}`, null);

                        return this.catalog[code]?.abbreviations.includes(saved) ? saved : null;
                    },

                    toggleLupa() {
                        this.lupa = ! this.lupa;
                        remember('odontograma.lupa', this.lupa ? '1' : '0');

                        if (! this.lupa) {
                            this.zoom = null;
                            this.paint();
                        }
                    },

                    toggleWriting() {
                        this.writing = ! this.writing;
                        remember('odontograma.escribir', this.writing ? '1' : '0');

                        if (this.writing) {
                            this.$nextTick(() => this.$refs.dictation?.focus());
                        }
                    },

                    tap(tooth, surface = null) {
                        const condition = this.current;

                        // Sin hallazgo elegido, el toque muestra lo que tiene la pieza.
                        if (! condition) {
                            this.$wire.mark(tooth, surface);

                            return;
                        }

                        // Con la lupa, el primer toque abre la pieza en grande y ahí se marcan las caras.
                        if (condition.appliesTo === 'surface' && this.lupa && this.zoom !== tooth) {
                            this.openZoom(tooth);

                            return;
                        }

                        if (condition.appliesTo === 'span' && this.spanStart === null) {
                            this.spanStart = tooth;
                            this.paint();

                            return;
                        }

                        const onSurface = condition.appliesTo === 'surface';
                        const from = condition.appliesTo === 'span' ? this.spanStart : tooth;
                        const to = condition.appliesTo === 'span' ? tooth : null;
                        const call = () => this.$wire.mark(from, onSurface ? surface : null, this.tool, this.state, this.abbreviation, to);
                        this.spanStart = null;

                        // Sin superficie no hay qué pintar: el servidor avisa qué falta.
                        if (onSurface && ! surface) {
                            call();

                            return;
                        }

                        const key = onSurface ? `${tooth}-${surface}` : `${from}`;
                        const shown = onSurface ? this.surface(tooth, surface)?.getAttribute('class').match(/\bis-(bad|good)\b/)?.[1] : null;
                        this.pending[key] = {
                            tooth: from,
                            surface: onSurface ? surface : null,
                            paint: shown === this.state ? 'none' : this.state,
                            count: (this.pending[key]?.count ?? 0) + 1,
                        };
                        this.paint();

                        call().finally(() => {
                            if (--this.pending[key].count === 0) {
                                delete this.pending[key];
                            }

                            this.paint();
                        });
                    },

                    treat(entryId, code) {
                        this.$wire.markTreated(entryId, this.savedAbbreviation(this.catalog[code]?.treatedAs));
                    },

                    openZoom(tooth) {
                        const piece = this.$root.querySelector(`[data-tooth-group="${tooth}"]`)?.getBoundingClientRect();

                        // La pieza ampliada se abre abajo: si taparía la pieza tocada, el dibujo sube.
                        if (this.zoom === null && piece && piece.bottom > window.innerHeight - 400) {
                            this.$root.querySelector('.od-scroll')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
                        }

                        this.zoom = tooth;
                        this.paint();
                    },

                    face(zone) {
                        return this.faces[zone] ?? { surface: '', state: 'none' };
                    },

                    zoomStep(delta) {
                        const teeth = [...this.$root.querySelectorAll('[data-tooth-group]')].map((el) => Number(el.dataset.toothGroup));
                        const index = teeth.indexOf(this.zoom);

                        this.zoom = teeth[(index + delta + teeth.length) % teeth.length];
                        this.paint();
                    },

                    surface(tooth, surface) {
                        return this.$root.querySelector(`.od-surface[data-tooth="${tooth}"][data-surface="${surface}"]`);
                    },

                    recount() {
                        const checked = [...this.$root.querySelectorAll('[data-suggestion]:checked:not(:disabled)')];

                        this.picked = checked.length;
                        this.planTotal = checked.reduce((total, el) => total + Number(el.dataset.price || 0), 0);
                    },

                    money(amount) {
                        return `S/ ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    },

                    applySuggestions() {
                        const keys = [...this.$root.querySelectorAll('[data-suggestion]:checked:not(:disabled)')].map((el) => el.value);

                        if (keys.length) {
                            this.$wire.applySuggestions(keys);
                        }
                    },

                    paint() {
                        this.$root.querySelectorAll('[data-shown]').forEach((el) => {
                            el.setAttribute('class', el.dataset.shown);
                            el.removeAttribute('data-shown');
                        });
                        this.$root.querySelectorAll('[data-busy], [data-span-start], [data-zoomed]').forEach((el) => {
                            el.removeAttribute('data-busy');
                            el.removeAttribute('data-span-start');
                            el.removeAttribute('data-zoomed');
                        });

                        Object.values(this.pending).forEach(({ tooth, surface, paint }) => {
                            if (! surface) {
                                this.$root.querySelector(`[data-tooth-group="${tooth}"]`)?.setAttribute('data-busy', '');

                                return;
                            }

                            const el = this.surface(tooth, surface);

                            if (el) {
                                el.dataset.shown = el.getAttribute('class');
                                el.setAttribute('class', el.dataset.shown.replace(/\bis-(bad|good|none)\b/, `is-${paint}`));
                            }
                        });

                        if (this.spanStart !== null) {
                            this.$root.querySelector(`[data-tooth-group="${this.spanStart}"]`)?.setAttribute('data-span-start', '');
                        }

                        // La pieza ampliada copia los colores del dibujo, con lo pendiente ya pintado.
                        if (this.zoom !== null) {
                            this.$root.querySelector(`[data-tooth-group="${this.zoom}"]`)?.setAttribute('data-zoomed', '');
                            this.faces = Object.fromEntries(['top', 'right', 'bottom', 'left', 'center'].map((zone) => {
                                const el = this.$root.querySelector(`.od-surface[data-tooth="${this.zoom}"][data-zone="${zone}"]`);

                                return [zone, { surface: el?.dataset.surface ?? '', state: el?.getAttribute('class').match(/\bis-(bad|good|none)\b/)?.[1] ?? 'none' }];
                            }));
                        }

                        this.recount();
                    },
                }));

                window.Alpine ? register() : document.addEventListener('alpine:init', register);
            })();
        </script>
    @endif
</div>
