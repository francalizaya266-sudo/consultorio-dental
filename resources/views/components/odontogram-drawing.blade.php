@props(['odontogram', 'rows', 'teeth', 'spans', 'interactive' => false, 'editing' => false, 'selectedTooth' => null, 'spanStart' => null])
@use('App\Livewire\OdontogramChart')
@use('App\Support\Teeth')
{{-- Dibujo del odontograma (norma MINSA): el mismo en la pantalla de la atención y en la historia impresa. --}}
@php
    $W = 46;
    $D = 30;
    $d = 12;
    $rowHeight = 84;
    $maxTeeth = collect($rows)->map(fn ($row) => count($row))->max() ?? 16;
    $width = $maxTeeth * $W;
    $color = fn (string $state) => $state === 'good' ? '#2563eb' : '#dc2626';
@endphp
@once
    <style>
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
    </style>
@endonce
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

    <svg class="od-svg {{ $editing ? 'is-editing' : '' }}" width="{{ $width }}" height="{{ $rowHeight }}" viewBox="0 0 {{ $width }} {{ $rowHeight }}" role="group" aria-label="Fila {{ $r + 1 }}" @if ($r === 1 && count($rows) === 2) style="margin-top: .25rem" @endif>
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

            <g @if ($interactive) wire:key="t-{{ $odontogram->id }}-{{ $tooth }}" data-tooth-group="{{ $tooth }}" @endif>
                @if ($selectedTooth === $tooth || $spanStart === $tooth)
                    <rect x="{{ $cx - $W / 2 + 1 }}" y="1" width="{{ $W - 2 }}" height="{{ $rowHeight - 2 }}" rx="6" class="{{ $spanStart === $tooth ? 'od-span-start' : 'od-selected' }}" />
                @endif

                {{-- Al editar, el toque lo recibe el editor del navegador (pinta al instante); si no, va directo al servidor. --}}
                <g @if ($interactive) class="od-hit" @if ($editing) x-on:click="tap({{ $tooth }})" @else wire:click="mark({{ $tooth }})" @endif @endif>
                    @if ($data['labels'])
                        <title>Pieza {{ $tooth }}: {{ collect($data['labels'])->map(fn ($label) => "{$label['text']} {$label['meaning']}")->implode(', ') }}</title>
                    @endif
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
                        @class(['od-surface', 'od-hit' => $interactive, 'is-'.($data['surfaces'][$surface] ?? 'none')])
                        @if ($interactive) data-tooth="{{ $tooth }}" data-surface="{{ $surface }}" data-zone="{{ $zone }}" @if ($editing) x-on:click="tap({{ $tooth }}, '{{ $surface }}')" @else wire:click="mark({{ $tooth }}, '{{ $surface }}')" @endif @endif
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
