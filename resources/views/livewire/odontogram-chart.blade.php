@use('App\Livewire\OdontogramChart')
@use('App\Support\Teeth')
@php
    $W = 46;
    $D = 30;
    $d = 12;
    $rowHeight = 84;
    $maxTeeth = collect($rows)->map(fn ($row) => count($row))->max() ?? 16;
    $width = $maxTeeth * $W;
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
        .od-svg { display: block; margin: 0 auto; font-family: inherit; }
        .od-svg .od-surface { fill: #fff; stroke: #94a3b8; stroke-width: 1; }
        .od-svg .od-surface.is-bad { fill: #dc2626; }
        .od-svg .od-surface.is-good { fill: #2563eb; }
        .od-svg .od-hit { cursor: pointer; }
        .od-svg.is-editing .od-surface:not(.is-bad):not(.is-good):hover { fill: var(--primary-100); }
        .od-svg .od-num { font-size: 11px; fill: var(--gray-600); text-anchor: middle; font-weight: 600; }
        .od-svg .od-box { fill: #fff; stroke: #cbd5e1; }
        .od-svg .od-label { font-size: 8.5px; text-anchor: middle; font-weight: 700; }
        .od-svg .od-selected { fill: var(--primary-100); }
        .od-svg .od-span-start { fill: #fef3c7; }
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
            @foreach ($rows as $r => $row)
                @php
                    $upper = Teeth::isUpper($row[0]);
                    $offset = (($maxTeeth - count($row)) / 2) * $W;
                    $positions = collect($row)->mapWithKeys(fn ($tooth, $i) => [$tooth => $offset + $i * $W + $W / 2]);
                    // Arriba: número, siglas, pieza y prótesis debajo. Abajo, al revés.
                    $numY = $upper ? 12 : $rowHeight - 4;
                    $boxY = $upper ? 17 : $rowHeight - 31;
                    $toothY = $upper ? 35 : $rowHeight - 69;
                    $spanY = $upper ? 76 : 8;
                    $rowSpans = $spans->filter(fn ($s) => $positions->has($s->tooth) && $positions->has($s->to_tooth));
                @endphp

                <svg class="od-svg {{ $canEdit && $current ? 'is-editing' : '' }}" width="{{ $width }}" height="{{ $rowHeight }}" viewBox="0 0 {{ $width }} {{ $rowHeight }}" role="group" aria-label="Fila {{ $r + 1 }}" @if ($r === 1 && count($rows) === 2) style="margin-top: .25rem" @endif>
                    <line x1="{{ $width / 2 }}" x2="{{ $width / 2 }}" y1="2" y2="{{ $rowHeight - 2 }}" stroke="#e2e8f0" stroke-dasharray="3 3" />

                    @foreach ($row as $tooth)
                        @php
                            $cx = $positions[$tooth];
                            $x0 = $cx - $D / 2;
                            $y0 = $toothY;
                            $x1 = $x0 + $D;
                            $y1 = $y0 + $D;
                            $ix0 = $x0 + ($D - $d) / 2;
                            $iy0 = $y0 + ($D - $d) / 2;
                            $ix1 = $ix0 + $d;
                            $iy1 = $iy0 + $d;
                            $layout = Teeth::layout($tooth);
                            $data = $teeth[$tooth] ?? ['surfaces' => [], 'marks' => [], 'labels' => []];
                            $shapes = [
                                'top' => "{$x0},{$y0} {$x1},{$y0} {$ix1},{$iy0} {$ix0},{$iy0}",
                                'right' => "{$x1},{$y0} {$x1},{$y1} {$ix1},{$iy1} {$ix1},{$iy0}",
                                'bottom' => "{$x1},{$y1} {$x0},{$y1} {$ix0},{$iy1} {$ix1},{$iy1}",
                                'left' => "{$x0},{$y1} {$x0},{$y0} {$ix0},{$iy0} {$ix0},{$iy1}",
                                'center' => "{$ix0},{$iy0} {$ix1},{$iy0} {$ix1},{$iy1} {$ix0},{$iy1}",
                            ];
                            $marks = $data['marks'];
                            $outline = collect($marks)->except(['ausente', 'extraccion_indicada', 'corona', 'corona_temporal', 'tratamiento_pulpar', 'fractura'])->last();
                        @endphp

                        <g wire:key="t-{{ $odontogram->id }}-{{ $tooth }}">
                            @if ($selectedTooth === $tooth || $spanStart === $tooth)
                                <rect x="{{ $cx - $W / 2 + 1 }}" y="1" width="{{ $W - 2 }}" height="{{ $rowHeight - 2 }}" rx="6" class="{{ $spanStart === $tooth ? 'od-span-start' : 'od-selected' }}" />
                            @endif

                            <g class="od-hit" wire:click="mark({{ $tooth }})">
                                <text x="{{ $cx }}" y="{{ $numY }}" class="od-num">{{ $tooth }}</text>
                                <rect x="{{ $x0 }}" y="{{ $boxY }}" width="{{ $D }}" height="14" rx="2" class="od-box" />
                                <text x="{{ $cx }}" y="{{ $boxY + 10.5 }}" class="od-label">
                                    @foreach (array_slice($data['labels'], 0, 2) as $i => $label)
                                        <tspan fill="{{ $color($label['state']) }}">{{ $i ? ' ' : '' }}{{ $label['text'] }}</tspan>
                                    @endforeach
                                </text>
                            </g>

                            @foreach ($shapes as $zone => $points)
                                @php $surface = $layout[$zone]; @endphp
                                <polygon
                                    points="{{ $points }}"
                                    @class(['od-surface od-hit', 'is-'.($data['surfaces'][$surface] ?? 'none')])
                                    wire:click="mark({{ $tooth }}, '{{ $surface }}')"
                                ><title>{{ $tooth }} {{ Teeth::surfaceName($surface) }}</title></polygon>
                            @endforeach

                            @if ($outline)
                                <rect x="{{ $x0 - 2 }}" y="{{ $y0 - 2 }}" width="{{ $D + 4 }}" height="{{ $D + 4 }}" fill="none" stroke="{{ $color($outline) }}" stroke-width="2" rx="2" pointer-events="none" />
                            @endif

                            @foreach (['ausente', 'extraccion_indicada'] as $code)
                                @isset($marks[$code])
                                    <g stroke="{{ $color($marks[$code]) }}" stroke-width="3" stroke-linecap="round" pointer-events="none">
                                        <line x1="{{ $x0 - 1 }}" y1="{{ $y0 - 1 }}" x2="{{ $x1 + 1 }}" y2="{{ $y1 + 1 }}" />
                                        <line x1="{{ $x1 + 1 }}" y1="{{ $y0 - 1 }}" x2="{{ $x0 - 1 }}" y2="{{ $y1 + 1 }}" />
                                    </g>
                                @endisset
                            @endforeach

                            @foreach (['corona', 'corona_temporal'] as $code)
                                @isset($marks[$code])
                                    <circle cx="{{ $cx }}" cy="{{ $y0 + $D / 2 }}" r="{{ $D / 2 + 4 }}" fill="none" stroke="{{ $color($marks[$code]) }}" stroke-width="2.5" pointer-events="none" />
                                @endisset
                            @endforeach

                            @isset($marks['tratamiento_pulpar'])
                                <line x1="{{ $cx }}" y1="{{ $y0 + 2 }}" x2="{{ $cx }}" y2="{{ $y1 - 2 }}" stroke="{{ $color($marks['tratamiento_pulpar']) }}" stroke-width="3" stroke-linecap="round" pointer-events="none" />
                            @endisset

                            @isset($marks['fractura'])
                                <line x1="{{ $x0 + 3 }}" y1="{{ $y1 - 3 }}" x2="{{ $x1 - 3 }}" y2="{{ $y0 + 3 }}" stroke="{{ $color($marks['fractura']) }}" stroke-width="2.5" stroke-linecap="round" pointer-events="none" />
                            @endisset
                        </g>
                    @endforeach

                    @foreach ($rowSpans as $span)
                        @php
                            $a = min($positions[$span->tooth], $positions[$span->to_tooth]);
                            $b = max($positions[$span->tooth], $positions[$span->to_tooth]);
                            $stroke = $color($span->state->value);
                        @endphp
                        <g stroke="{{ $stroke }}" stroke-width="2.5" pointer-events="none">
                            <line x1="{{ $a - 8 }}" y1="{{ $spanY }}" x2="{{ $b + 8 }}" y2="{{ $spanY }}" />
                            <line x1="{{ $a - 8 }}" y1="{{ $spanY - 4 }}" x2="{{ $a - 8 }}" y2="{{ $spanY + 4 }}" />
                            <line x1="{{ $b + 8 }}" y1="{{ $spanY - 4 }}" x2="{{ $b + 8 }}" y2="{{ $spanY + 4 }}" />
                        </g>
                        <text x="{{ ($a + $b) / 2 }}" y="{{ $spanY + ($upper ? -3 : 11) }}" class="od-label" fill="{{ $stroke }}" style="paint-order: stroke; stroke: #fff; stroke-width: 3px">{{ OdontogramChart::label($span) }}</text>
                    @endforeach
                </svg>
            @endforeach
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
