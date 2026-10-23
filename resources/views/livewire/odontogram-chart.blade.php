@use('App\Services\Clinical\OdontogramService')
@use('App\Support\Teeth')
@php
    $color = fn (string $state) => $state === 'good' ? '#2563eb' : '#dc2626';
@endphp

<div class="od-wrap" @if ($canEdit) x-data="odontogramEditor(@js($catalog))" @endif>
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
        .od-chip-sm { padding: .1rem .5rem; font-size: .74rem; font-weight: 600; }
        .od-siglas { display: inline-flex; flex-wrap: wrap; gap: .3rem; align-items: center; }
        .od-treated { color: var(--primary-600); font-weight: 600; font-size: .78rem; margin-right: .5rem; cursor: pointer; white-space: nowrap; }
        /* El dibujo usa el ancho disponible (los dientes crecen hasta 1,5 veces) y en el celular se desliza. */
        .od-scroll .od-svg { width: 100%; height: auto; max-width: 69rem; min-width: 38rem; }
        .od-svg [data-busy] .od-surface.is-none, .od-svg [data-busy] .od-box { fill: var(--primary-100); }
        .od-svg [data-span-start] .od-surface.is-none, .od-svg [data-span-start] .od-box { fill: #fef3c7; }
        @media (prefers-reduced-motion: no-preference) {
            .od-svg [data-busy] { animation: od-busy 1s ease-in-out infinite; }
            @keyframes od-busy { 50% { opacity: .6; } }
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
                    <x-filament::input.wrapper style="max-width: 15rem">
                        <x-filament::input.select x-on:change="select($event.target.value || null)" x-effect="$el.value = [...$el.options].some((option) => option.value === tool) ? tool : ''" aria-label="Otros hallazgos">
                            <option value="">Otros hallazgos…</option>
                            @foreach ($others as $condition)
                                <option value="{{ $condition->code }}">{{ $condition->name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="od-options" x-show="tool" x-cloak>
                    <span class="od-state" role="group" aria-label="Estado">
                        <button type="button" x-on:click="setState('bad')" x-bind:class="{ 'is-bad': state === 'bad' }" x-bind:disabled="! current?.bad">Por tratar</button>
                        <button type="button" x-on:click="setState('good')" x-bind:class="{ 'is-good': state === 'good' }" x-bind:disabled="! current?.good">Tratado</button>
                    </span>

                    <span class="od-siglas" x-show="current?.abbreviations.length" role="group" aria-label="Sigla">
                        Sigla
                        <template x-for="option in current?.abbreviations ?? []" x-bind:key="option">
                            <button type="button" class="od-chip od-chip-sm" x-bind:class="{ 'is-active': abbreviation === option }" x-on:click="setAbbreviation(option)" x-text="option"></button>
                        </template>
                    </span>

                    <span class="od-hint" x-text="hint"></span>

                    <button type="button" class="od-link" x-on:click="select(null)">Listo</button>
                </div>

                <p class="od-muted" x-show="! tool">Elige un hallazgo y toca la pieza. Sin hallazgo elegido, al tocar una pieza ves lo que tiene.</p>
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
                                <td>{{ $entry->condition->name }}{{ $entry->abbreviation ? " ({$entry->abbreviation})" : '' }}</td>
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
                 */
                const register = () => Alpine.data('odontogramEditor', (catalog) => ({
                    catalog,
                    tool: null,
                    state: 'bad',
                    abbreviation: null,
                    spanStart: null,
                    // Color elegido por hallazgo mientras dura la página (la sigla se recuerda también después).
                    states: {},
                    pending: {},
                    stopListening: null,

                    init() {
                        this.stopListening = Livewire.hook('morphed', ({ component }) => {
                            if (component.id === this.$wire.$id) {
                                this.paint();
                            }
                        });
                    },

                    destroy() {
                        this.stopListening?.();
                    },

                    get current() {
                        return this.tool ? this.catalog[this.tool] : null;
                    },

                    get hint() {
                        const where = {
                            surface: 'Toca la superficie de la pieza.',
                            span: this.spanStart === null ? 'Toca la primera pieza y luego la última.' : `Ahora toca la última pieza (desde la ${this.spanStart}).`,
                        }[this.current?.appliesTo] ?? 'Toca la pieza.';

                        return `${where} Tocar de nuevo la quita.`;
                    },

                    select(code) {
                        const condition = code && code !== this.tool ? this.catalog[code] : null;

                        this.tool = condition ? code : null;
                        // Lo que se puede tener sano o tratado (restauración, corona…) empieza en azul; lo demás, en rojo.
                        this.state = ! condition ? 'bad' : (condition.good && condition.bad ? (this.states[code] ?? 'good') : (condition.good ? 'good' : 'bad'));
                        this.abbreviation = condition ? this.savedAbbreviation(code) : null;
                        this.spanStart = null;
                        this.paint();
                    },

                    setState(state) {
                        if (this.current?.[state]) {
                            this.state = state;
                            this.states[this.tool] = state;
                        }
                    },

                    setAbbreviation(option) {
                        this.abbreviation = this.abbreviation === option ? null : option;

                        try {
                            localStorage.setItem(`odontograma.sigla.${this.tool}`, this.abbreviation ?? '');
                        } catch (error) {}
                    },

                    savedAbbreviation(code) {
                        try {
                            const saved = localStorage.getItem(`odontograma.sigla.${code}`);

                            return this.catalog[code]?.abbreviations.includes(saved) ? saved : null;
                        } catch (error) {
                            return null;
                        }
                    },

                    tap(tooth, surface = null) {
                        const condition = this.current;

                        // Sin hallazgo elegido, el toque muestra lo que tiene la pieza.
                        if (! condition) {
                            this.$wire.mark(tooth, surface);

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

                    surface(tooth, surface) {
                        return this.$root.querySelector(`.od-surface[data-tooth="${tooth}"][data-surface="${surface}"]`);
                    },

                    paint() {
                        this.$root.querySelectorAll('[data-shown]').forEach((el) => {
                            el.setAttribute('class', el.dataset.shown);
                            el.removeAttribute('data-shown');
                        });
                        this.$root.querySelectorAll('[data-busy], [data-span-start]').forEach((el) => {
                            el.removeAttribute('data-busy');
                            el.removeAttribute('data-span-start');
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
                    },
                }));

                window.Alpine ? register() : document.addEventListener('alpine:init', register);
            })();
        </script>
    @endif
</div>
