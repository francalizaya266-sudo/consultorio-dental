@use('App\Support\Money')
{{--
    Odontograma interactivo (App\Livewire\OdontogramChart). El dibujo y la lista los arma el servidor; la ficha
    de la pieza, el pincel, el buscador, los filtros, la pantalla completa y los atajos viven en el navegador
    (public/js/odontogram.js), con los datos de cada pieza que van en [data-odv-state]. Estilos: public/css/panel.css.
--}}
@php
    $missingCodes = ['ausente', 'edentulo_total'];
    $todayWord = $canEdit ? 'Hoy' : 'Nuevo';
    $filters = [
        'bad' => ['Por tratar', 'Piezas con algo por tratar (rojo)'],
        'good' => ['Tratado', 'Piezas con algo tratado o en buen estado (azul)'],
        'missing' => ['Ausentes', 'Piezas ausentes'],
        'today' => [$todayWord, $canEdit ? 'Piezas marcadas en esta atención' : 'Piezas que cambiaron en esta versión'],
    ];
    // Las cinco caras de la pieza en grande, en un cuadro de 100 × 100: forma y dónde va su letra.
    $bigFaces = [
        'top' => ['0,0 100,0 70,30 30,30', 50, 15],
        'right' => ['100,0 100,100 70,70 70,30', 85, 50],
        'bottom' => ['100,100 0,100 30,70 70,70', 50, 85],
        'left' => ['0,100 0,0 30,30 30,70', 15, 50],
        'center' => ['30,30 70,30 70,70 30,70', 50, 50],
    ];
    $pending = collect($groups)->where('state', 'bad');
    $done = collect($groups)->where('state', 'good');
@endphp

<div class="odv"
    x-data="odontogram(@js($config))"
    x-bind:class="{ 'is-expanded': expanded, 'has-tool': tool, 'has-face-tool': current?.appliesTo === 'surface' }"
    x-bind:data-filter="filter"
    x-on:keydown="onKey($event)"
>
    @if (! $odontogram)
        <p class="odv-muted">Todavía no hay odontograma. Se crea solo al iniciar la primera atención.</p>
    @else
        <div class="odv-frame">
            <div class="odv-head">
                {{-- Los filtros son también la leyenda: rojo por tratar, azul tratado. --}}
                <div class="odv-filters" role="group" aria-label="Mostrar">
                    <button type="button" class="odv-filter" x-on:click="setFilter(null)" x-bind:aria-pressed="filter === null">Todo</button>
                    @foreach ($filters as $key => [$label, $title])
                        @continue($key === 'today' && ! $summary['today'])
                        <button type="button" class="odv-filter" title="{{ $title }}" x-on:click="setFilter(@js($key))" x-bind:aria-pressed="filter === @js($key)" @disabled(! $summary[$key])>
                            <i class="odv-swatch is-{{ $key }}" aria-hidden="true"></i>{{ $label }}<b>{{ $summary[$key] }}</b>
                        </button>
                    @endforeach
                </div>

                <div class="odv-head-side">
                    @if ($this->versions->count() > 1)
                        <x-filament::input.wrapper class="odv-select">
                            <x-filament::input.select wire:change="showVersion($event.target.value)" aria-label="Versión">
                                @foreach ($this->versions as $version)
                                    <option value="{{ $version->id }}" @selected($version->id === $odontogram->id)>
                                        {{ $version->created_at->format('d/m/Y') }} · {{ $version->doctor?->name ?? 'Sin doctor' }} · {{ $version->type->getLabel() }}
                                    </option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @elseif (! $canEdit)
                        <span class="odv-muted">{{ $odontogram->type->getLabel() }} · {{ $odontogram->created_at->format('d/m/Y') }} · {{ $odontogram->doctor?->name }}</span>
                    @endif

                    @if ($canEdit)
                        <x-filament::input.wrapper class="odv-select">
                            <x-filament::input.select wire:change="setDentition($event.target.value)" aria-label="Dentición">
                                @foreach (\App\Enums\Dentition::cases() as $dentition)
                                    <option value="{{ $dentition->value }}" @selected($dentition === $odontogram->dentition)>{{ $dentition->getLabel() }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif

                    <details class="odv-keys">
                        <summary>Atajos</summary>
                        <dl>
                            <dt><kbd>/</kbd></dt><dd>Buscar o escribir</dd>
                            <dt><kbd>3</kbd> <kbd>6</kbd></dt><dd>Abrir la pieza 36</dd>
                            <dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>Pieza de al lado</dd>
                            <dt><kbd>↑</kbd> <kbd>↓</kbd></dt><dd>La otra arcada</dd>
                            @if ($canEdit)
                                <dt><kbd>M</kbd> <kbd>O</kbd> <kbd>D</kbd> <kbd>V</kbd> <kbd>L</kbd></dt><dd>Marcar esa cara</dd>
                                <dt><kbd>Enter</kbd></dt><dd>Marcar la pieza</dd>
                            @endif
                            <dt><kbd>F</kbd></dt><dd>Pantalla completa</dd>
                            <dt><kbd>Esc</kbd></dt><dd>Cerrar o soltar</dd>
                        </dl>
                    </details>

                    <button type="button" class="odv-btn" x-on:click="toggleExpanded()" x-bind:aria-pressed="expanded" x-bind:title="expanded ? 'Volver (Esc)' : 'Pantalla completa (F)'">
                        <span x-show="! expanded">{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::ArrowsPointingOut, size: \Filament\Support\Enums\IconSize::Small) }}</span>
                        <span x-show="expanded" x-cloak>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::ArrowsPointingIn, size: \Filament\Support\Enums\IconSize::Small) }}</span>
                        <span x-text="expanded ? 'Volver' : 'Ampliar'">Ampliar</span>
                    </button>
                </div>
            </div>

            @if ($canEdit)
                {{-- Elegir hallazgo, color y sigla no espera al servidor. --}}
                <div class="odv-tools" wire:ignore>
                    <div class="odv-omni" x-on:click.outside="searching = false">
                        {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::MagnifyingGlass, size: \Filament\Support\Enums\IconSize::Small) }}
                        <input type="text" x-ref="omni" x-model="query"
                            x-on:focus="searching = true"
                            x-on:input="onQuery()"
                            x-on:keydown.down.prevent="moveMatch(1)"
                            x-on:keydown.up.prevent="moveMatch(-1)"
                            x-on:keydown.enter.prevent="submitQuery()"
                            x-on:keydown.escape.prevent="clearQuery()"
                            placeholder="Busca o escribe «36 MO caries»"
                            aria-label="Buscar un hallazgo, o escribir o dictar hallazgos"
                            autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="done">
                        <kbd class="odv-omni-key" x-show="! query" aria-hidden="true">/</kbd>
                        <button type="button" class="odv-omni-clear" x-show="query" x-cloak x-on:click="clearQuery()" aria-label="Borrar">×</button>

                        <div class="odv-matches" x-show="searching && ! dictating" x-cloak role="listbox" aria-label="Hallazgos">
                            <p class="odv-matches-tip" x-show="! query">También puedes escribir o dictar varios: <b>36 MO caries, 18 ausente</b>.</p>
                            <template x-for="(code, index) in matches" x-bind:key="code">
                                <button type="button" role="option" x-bind:aria-selected="index === highlighted" x-on:mouseenter="highlighted = index" x-on:click="pick(code)">
                                    <span x-text="catalog[code].name"></span><small x-text="catalog[code].abbreviations.join(' ')"></small>
                                </button>
                            </template>
                            <p class="odv-matches-empty" x-show="query && ! matches.length">No hay un hallazgo con ese nombre. Para marcarlo en una pieza, empieza por su número: «36 O caries».</p>
                        </div>
                    </div>

                    <div class="odv-chips" aria-label="Hallazgos frecuentes">
                        @foreach ($frequent as $condition)
                            <button type="button" class="odv-chip" title="{{ $condition->name }}" x-bind:aria-pressed="tool === @js($condition->code)" x-on:click="select(@js($condition->code))">{{ \App\Livewire\OdontogramChart::CHIP_NAMES[$condition->code] ?? $condition->name }}</button>
                        @endforeach
                        @foreach ($this->favorites as $condition)
                            <button type="button" class="odv-chip is-favorite" title="Lo marcas seguido" x-bind:aria-pressed="tool === @js($condition->code)" x-on:click="select(@js($condition->code))">{{ $condition->name }}</button>
                        @endforeach
                        <template x-if="tool && ! frequent.includes(tool) && ! favorites.includes(tool)">
                            <button type="button" class="odv-chip" aria-pressed="true" x-on:click="select(null)" x-text="current.name"></button>
                        </template>
                    </div>

                    <div class="odv-brush" aria-live="polite">
                        <p class="odv-muted" x-show="! tool">Toca una pieza para ver su ficha, o elige un hallazgo y toca pieza por pieza.</p>
                        <template x-if="tool">
                            <div class="odv-brush-in">
                                <span class="odv-seg" role="group" aria-label="Estado">
                                    <button type="button" class="is-bad" x-on:click="setState('bad')" x-bind:aria-pressed="state === 'bad'" x-bind:disabled="! current?.bad">Por tratar</button>
                                    <button type="button" class="is-good" x-on:click="setState('good')" x-bind:aria-pressed="state === 'good'" x-bind:disabled="! current?.good">Tratado</button>
                                </span>
                                <span class="odv-siglas" x-show="current?.abbreviations.length" role="group" aria-label="Sigla">
                                    <template x-for="option in current?.abbreviations ?? []" x-bind:key="option">
                                        <button type="button" class="odv-sigla" x-bind:aria-pressed="abbreviation === option" x-on:click="setAbbreviation(option)" x-bind:title="meaning(option) ?? option">
                                            <b x-text="option"></b><span x-show="word(option)" x-text="word(option)"></span>
                                        </button>
                                    </template>
                                </span>
                                <span class="odv-hint" x-text="hint"></span>
                                <button type="button" class="odv-link" x-on:click="select(null)">Listo</button>
                            </div>
                        </template>
                    </div>
                </div>

                @if ($this->dictated)
                    <div class="odv-parse">
                        <ul>
                            @foreach ($this->dictated as $finding)
                                @if ($finding['error'])
                                    <li class="is-error">⚠ «{{ $finding['text'] }}»: {{ $finding['error'] }}</li>
                                @else
                                    <li class="is-ok">✓ {{ $finding['summary'] }}</li>
                                @endif
                            @endforeach
                        </ul>
                        <div class="odv-parse-foot">
                            <span class="odv-muted">Revisa lo que entendí: en el dibujo se resaltan las piezas. Para dictar, usa el micrófono del teclado (en Windows, Windows + H).</span>
                            <x-filament::button size="sm" x-on:click="submitQuery()" :disabled="collect($this->dictated)->every(fn ($finding) => $finding['error'])">
                                Agregar
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            @endif

            <div class="odv-stage" x-bind:class="{ 'has-card': tooth }">
                <div class="odv-sheet" x-ref="sheet" tabindex="0" aria-label="Odontograma: toca una pieza para ver su ficha; con el teclado, flechas para moverte" x-on:click="onSheetClick($event)">
                    <div class="odv-sides" aria-hidden="true"><span>Derecha del paciente</span><span>Izquierda del paciente</span></div>
                    <div class="odv-scroll">
                        <x-odontogram-drawing :odontogram="$odontogram" :rows="$rows" :teeth="$teeth" :spans="$spans" :today="$today" :preview="$preview" interactive :editing="$canEdit" />
                    </div>
                </div>

                {{-- La ficha de la pieza: se arma en el navegador con los datos de [data-odv-state]. --}}
                <aside class="odv-card" wire:ignore x-show="tooth" x-cloak aria-label="Ficha de la pieza">
                    <template x-if="tooth">
                        <div class="odv-card-in">
                            <div class="odv-card-head">
                                <button type="button" class="odv-icon-btn" x-on:click="step(-1)" aria-label="Pieza anterior">‹</button>
                                <p class="odv-card-title"><strong x-text="focus"></strong><span x-text="tooth.name"></span></p>
                                <button type="button" class="odv-icon-btn" x-on:click="step(1)" aria-label="Pieza siguiente">›</button>
                                <button type="button" class="odv-icon-btn" x-on:click="closeTooth()" aria-label="Cerrar la ficha">×</button>
                            </div>

                            <div class="odv-card-body">
                                <svg class="odv-faces" viewBox="-2 -2 104 104" role="group" aria-label="Caras de la pieza">
                                    @foreach ($bigFaces as $zone => [$points, $x, $y])
                                        <g class="odv-face" x-bind:class="`is-${face('{{ $zone }}').state}`" x-on:click="tapFace('{{ $zone }}')">
                                            <polygon points="{{ $points }}" />
                                            <text x="{{ $x }}" y="{{ $y - 2 }}" class="odv-face-letter" x-text="face('{{ $zone }}').surface"></text>
                                            <text x="{{ $x }}" y="{{ $y + 8 }}" class="odv-face-name" x-text="surfaceNames[face('{{ $zone }}').surface] ?? ''"></text>
                                        </g>
                                    @endforeach
                                </svg>

                                <ul class="odv-card-list">
                                    <template x-for="entry in tooth.entries" x-bind:key="entry.ids.join('-')">
                                        <li>
                                            <i class="odv-dot" x-bind:class="`is-${entry.state}`" aria-hidden="true"></i>
                                            <span class="odv-card-text">
                                                <span x-text="entry.name"></span>
                                                <small x-text="`${entry.where} · ${entry.state === 'good' ? 'tratado' : 'por tratar'}`"></small>
                                            </span>
                                            <span class="odv-new" x-show="entry.new">{{ $todayWord }}</span>
                                            @if ($canEdit)
                                                <span class="odv-card-actions">
                                                    <button type="button" class="odv-treat" x-show="entry.treatable" x-on:click="treat(entry.ids, entry.code)">✓ Tratado</button>
                                                    <button type="button" class="odv-icon-btn is-small" x-on:click="$wire.remove(entry.ids)" aria-label="Quitar">×</button>
                                                </span>
                                            @endif
                                        </li>
                                    </template>
                                    <li class="odv-muted" x-show="! tooth.entries.length">Sin marcas.</li>
                                </ul>

                                @if ($canEdit)
                                    <div class="odv-card-tool">
                                        {{-- En la tableta y el celular el pincel de arriba no se ve: color y sigla también aquí. --}}
                                        <template x-if="current?.appliesTo === 'surface'">
                                            <div class="odv-card-brush">
                                                <span class="odv-seg" role="group" aria-label="Estado">
                                                    <button type="button" class="is-bad" x-on:click="setState('bad')" x-bind:aria-pressed="state === 'bad'" x-bind:disabled="! current?.bad">Por tratar</button>
                                                    <button type="button" class="is-good" x-on:click="setState('good')" x-bind:aria-pressed="state === 'good'" x-bind:disabled="! current?.good">Tratado</button>
                                                </span>
                                                <span class="odv-siglas" x-show="current?.abbreviations.length" role="group" aria-label="Sigla">
                                                    <template x-for="option in current?.abbreviations ?? []" x-bind:key="option">
                                                        <button type="button" class="odv-sigla" x-bind:aria-pressed="abbreviation === option" x-on:click="setAbbreviation(option)" x-bind:title="meaning(option) ?? option">
                                                            <b x-text="option"></b><span x-show="word(option)" x-text="word(option)"></span>
                                                        </button>
                                                    </template>
                                                </span>
                                            </div>
                                        </template>
                                        <p class="odv-faces-hint" x-text="facesHint"></p>
                                    </div>

                                    <div class="odv-quick">
                                        <span class="odv-quick-label">Marcar en esta pieza</span>
                                        <div class="odv-quick-list">
                                            <template x-for="code in quickList" x-bind:key="code">
                                                <button type="button" class="odv-chip" x-bind:aria-pressed="tool === code" x-on:click="addHere(code)" x-bind:title="catalog[code].name" x-text="catalog[code].short"></button>
                                            </template>
                                            <button type="button" class="odv-chip is-ghost" x-on:click="$refs.omni?.focus()">Otro…</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </template>
                </aside>
            </div>
        </div>

        <div hidden data-odv-state="{{ json_encode($state) }}"></div>

        {{-- Lo marcado, por tratar primero; las caras de una misma caries van juntas. --}}
        <div class="odv-list">
            @if ($groups === [])
                <p class="odv-muted">Sin hallazgos marcados.</p>
            @endif

            @foreach (['bad' => ['Por tratar', $pending], 'good' => ['Tratado o en buen estado', $done]] as $part => [$title, $rowsOfState])
                @if ($rowsOfState->isNotEmpty())
                    <section class="odv-list-part" data-part="{{ $part }}">
                        <h3><i class="odv-swatch is-{{ $part }}" aria-hidden="true"></i>{{ $title }} <span>{{ $rowsOfState->count() }}</span></h3>
                        <ul>
                            @foreach ($rowsOfState as $group)
                                @php $missing = in_array($group['code'], $missingCodes, true); @endphp
                                <li class="odv-item" wire:key="g-{{ implode('-', $group['ids']) }}"
                                    @if ($group['state'] === 'bad') data-bad @endif
                                    @if ($group['state'] === 'good' && ! $missing) data-good @endif
                                    @if ($missing) data-missing @endif
                                    @if ($group['new']) data-today @endif
                                >
                                    <button type="button" class="odv-item-tooth" x-on:click="openTooth({{ $group['tooth'] }})" title="Ver la pieza {{ $group['tooth'] }}">{{ $group['where'] }}</button>
                                    <span class="odv-item-text" @if ($group['meaning']) title="{{ $group['meaning'] }}" @endif>
                                        <span>{{ $group['name'] }}</span>
                                        @if ($group['surfaces'])
                                            <small>{{ $group['surfaces'] }}</small>
                                        @endif
                                    </span>
                                    @if ($group['new'])
                                        <span class="odv-new">{{ $todayWord }}</span>
                                    @endif
                                    @if ($canEdit)
                                        <span class="odv-item-actions">
                                            @if ($group['treatable'])
                                                <button type="button" class="odv-treat" data-treat x-on:click="treat(@js($group['ids']), @js($group['code']))">✓ Tratado</button>
                                            @endif
                                            <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" label="Quitar" wire:click="remove({{ json_encode($group['ids']) }})" />
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
        </div>

        @if ($suggestions !== [])
            <div class="odv-plan">
                <div class="odv-plan-head">
                    <strong>Para el diagnóstico y el plan</strong>
                    <span class="odv-muted">Sale de lo marcado en el odontograma. Quita lo que no va y agrégalo con un clic.</span>
                </div>

                <div>
                    @foreach ($suggestions as $row)
                        @php $price = $row['treatment'] && ! $row['planned'] ? (float) $row['treatment']->price_from : 0; @endphp
                        <label wire:key="s-{{ $odontogram->id }}-{{ $row['key'] }}" @class(['odv-plan-row', 'is-done' => $row['done']])>
                            <input type="checkbox" value="{{ $row['key'] }}" data-suggestion data-price="{{ $price }}" @checked($row['suggested'] || $row['done']) @disabled($row['done']) x-on:change="recount()">
                            <span class="odv-plan-text">
                                <span class="odv-plan-main"><b>{{ $row['tooth'] }}{{ $row['surfaces'] ? ' · '.$row['surfaces'] : '' }}</b> {{ $row['treatment']?->name ?? $row['diagnoses']->first()?->description }}</span>
                                <span class="odv-plan-sub">{{ implode(', ', $row['findings']) }}@foreach ($row['diagnoses'] as $code) · {{ $code->code }} {{ $code->description }}@endforeach</span>
                            </span>
                            <span class="odv-plan-side">
                                @if ($row['done'])
                                    <span class="odv-tag">Ya agregado</span>
                                @elseif ($row['planned'])
                                    <span class="odv-tag">Ya está en el plan</span>
                                @elseif ($row['optional'])
                                    <span class="odv-tag">Opcional</span>
                                @endif
                                @if ($row['treatment'] && ! $row['planned'])
                                    <span class="odv-plan-price">{{ $price > 0 ? Money::format($price) : 'Sin precio' }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="odv-plan-foot">
                    <span>Para el plan: <strong x-text="money(planTotal)"></strong></span>
                    <x-filament::button size="sm" icon="heroicon-m-plus" x-on:click="applySuggestions()" x-bind:disabled="! picked">
                        Agregar al diagnóstico y al plan
                    </x-filament::button>
                </div>
            </div>
        @endif

        @if ($canEdit)
            <div class="odv-notes">
                <div>
                    <label for="odv-specs-{{ $odontogram->id }}">Especificaciones</label>
                    <textarea id="odv-specs-{{ $odontogram->id }}" wire:model.blur="specifications" placeholder="Ej.: pieza 36 con restauración desadaptada"></textarea>
                </div>
                <div>
                    <label for="odv-obs-{{ $odontogram->id }}">Observaciones</label>
                    <textarea id="odv-obs-{{ $odontogram->id }}" wire:model.blur="observations"></textarea>
                </div>
            </div>
        @elseif ($odontogram->specifications || $odontogram->observations)
            <div class="odv-notes">
                @if ($odontogram->specifications)
                    <div><label>Especificaciones</label><p>{{ $odontogram->specifications }}</p></div>
                @endif
                @if ($odontogram->observations)
                    <div><label>Observaciones</label><p>{{ $odontogram->observations }}</p></div>
                @endif
            </div>
        @endif
    @endif
</div>
