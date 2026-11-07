@props(['odontogram', 'rows', 'teeth', 'spans', 'interactive' => false, 'editing' => false, 'preview' => [], 'today' => []])
@use('App\Livewire\OdontogramChart')
@use('App\Support\Teeth')
@use('App\Support\ToothDrawing')
{{--
    Dibujo del odontograma (norma MINSA), el mismo en la pantalla y en la historia impresa. Cada pieza tiene su
    recuadro de siglas, su forma (corona y raíces, según el tipo de diente), sus cinco caras y su número. Las
    piezas superiores tienen las raíces hacia arriba y las inferiores hacia abajo: los números quedan en el centro,
    donde se tocan las dos arcadas. Rojo = por tratar; azul = tratado o en buen estado.
--}}
@php
    $W = 46;
    $D = 26;
    $d = 10;
    $box = 15;
    $gap = 8;
    $maxTeeth = collect($rows)->map(fn ($row) => count($row))->max() ?? 16;
    $width = $maxTeeth * $W;
    $color = fn (string $state) => $state === 'good' ? '#2563eb' : '#dc2626';
    $uid = 'od'.($odontogram->id ?? 0);

    // Cada fila: dónde va el recuadro, la pieza (su borde oclusal), las caras y el número.
    $layout = [];
    $top = 0;
    foreach ($rows as $r => $row) {
        $upper = Teeth::isUpper($row[0]);
        $tall = ToothDrawing::tallest($row);
        if ($upper) {
            $boxY = $top + 4;
            $occlusalY = $boxY + $box + 6 + $tall;
            $surfaceY = $occlusalY + 7;
            $numY = $surfaceY + $D + 14;
            $height = $numY + 5 - $top;
        } else {
            $numY = $top + 13;
            $surfaceY = $numY + 6;
            $occlusalY = $surfaceY + $D + 7;
            $boxY = $occlusalY + $tall + 6;
            $height = $boxY + $box + 4 - $top;
        }
        $layout[$r] = compact('upper', 'boxY', 'occlusalY', 'surfaceY', 'numY', 'height') + ['top' => $top, 'offset' => (($maxTeeth - count($row)) / 2) * $W];
        $top += $height + ($upper && isset($rows[$r + 1]) && ! Teeth::isUpper($rows[$r + 1][0]) ? 2 : $gap);
    }
    $totalHeight = $top - $gap;
    // Donde se tocan las arcadas: una línea fina entre los números de arriba y los de abajo.
    $biteLine = collect($layout)->first(fn ($row, $r) => $row['upper'] && isset($layout[$r + 1]) && ! $layout[$r + 1]['upper']);
@endphp
@once
    <style>
        .odv-svg { display: block; margin: 0 auto; font-family: inherit; overflow: visible; }
        .odv-svg .odv-col { fill: transparent; }
        .odv-svg .odv-crown { fill: #fff; stroke: #7d8ca3; stroke-width: 1; }
        .odv-svg .odv-root { fill: #f5f7fa; stroke: #a5b2c4; stroke-width: .9; }
        .odv-svg .odv-palatal { fill: #edf1f6; stroke: #c4cdda; stroke-width: .8; }
        .odv-svg .is-missing .odv-crown, .odv-svg .is-missing .odv-root, .odv-svg .is-missing .odv-palatal { fill: none; stroke-dasharray: 2.5 2; opacity: .55; }
        .odv-svg .odv-crown.is-gone { fill: none; stroke-dasharray: 2.5 2; opacity: .55; }
        .odv-svg .odv-surface { fill: #fff; stroke: #94a3b8; stroke-width: 1; }
        .odv-svg .odv-surface.is-bad, .odv-svg .odv-on.is-bad { fill: #dc2626; }
        .odv-svg .odv-surface.is-good, .odv-svg .odv-on.is-good { fill: #2563eb; }
        .odv-svg .odv-on { opacity: .9; }
        .odv-svg .odv-box { fill: #fff; stroke: #cbd5e1; }
        .odv-svg .odv-num { font-size: 12px; font-weight: 600; fill: #475569; text-anchor: middle; }
        .odv-svg .odv-label { font-size: 9px; font-weight: 700; text-anchor: middle; }
        .odv-svg .odv-midline { stroke: #e2e8f0; stroke-dasharray: 3 3; }
        .odv-svg .odv-bite { stroke: #e2e8f0; }
        .odv-svg .odv-today { fill: #f59e0b; display: none; }
        .odv-svg [data-today] .odv-today { display: inline; }
    </style>
@endonce
<svg class="odv-svg{{ $editing ? ' is-editing' : '' }}" width="{{ $width }}" height="{{ $totalHeight }}" viewBox="0 0 {{ $width }} {{ $totalHeight }}" style="--odv-ratio: {{ round($width / max(1, $totalHeight), 3) }}" role="group" aria-label="Odontograma">
    <line class="odv-midline" x1="{{ $width / 2 }}" x2="{{ $width / 2 }}" y1="2" y2="{{ $totalHeight - 2 }}" />
    @if ($biteLine)
        <line class="odv-bite" x1="4" x2="{{ $width - 4 }}" y1="{{ $biteLine['top'] + $biteLine['height'] + 1 }}" y2="{{ $biteLine['top'] + $biteLine['height'] + 1 }}" />
    @endif

    @foreach ($rows as $r => $row)
        @php
            ['upper' => $upper, 'boxY' => $boxY, 'occlusalY' => $occlusalY, 'surfaceY' => $surfaceY, 'numY' => $numY, 'top' => $rowTop, 'height' => $rowHeight, 'offset' => $offset] = $layout[$r];
            $positions = collect($row)->mapWithKeys(fn ($tooth, $i) => [$tooth => $offset + $i * $W + $W / 2]);
            $rowSpans = $spans->filter(fn ($s) => $positions->has($s->tooth) && $positions->has($s->to_tooth));
            $sy = $upper ? 1 : -1;
            // A la altura de la corona: por ahí pasan los puentes y los aparatos.
            $crownLine = $occlusalY - $sy * 12;
        @endphp

        @foreach ($row as $tooth)
            @php
                $cx = $positions[$tooth];
                $shape = ToothDrawing::shape($tooth);
                $sx = Teeth::isPatientRight($tooth) ? 1 : -1;
                $data = $teeth[$tooth] ?? ['surfaces' => [], 'marks' => [], 'labels' => [], 'flags' => []];
                $marks = $data['marks'];
                $flags = $data['flags'] ?? [];
                $zones = Teeth::layout($tooth);
                $surfaceState = fn (string $letter) => $data['surfaces'][$letter] ?? null;
                $x0 = $cx - $D / 2;
                $y0 = $surfaceY;
                $x1 = $x0 + $D;
                $y1 = $y0 + $D;
                $ix0 = $x0 + ($D - $d) / 2;
                $iy0 = $y0 + ($D - $d) / 2;
                $ix1 = $ix0 + $d;
                $iy1 = $iy0 + $d;
                $shapes = [
                    'top' => "{$x0},{$y0} {$x1},{$y0} {$ix1},{$iy0} {$ix0},{$iy0}",
                    'right' => "{$x1},{$y0} {$x1},{$y1} {$ix1},{$iy1} {$ix1},{$iy0}",
                    'bottom' => "{$x1},{$y1} {$x0},{$y1} {$ix0},{$iy1} {$ix1},{$iy1}",
                    'left' => "{$x0},{$y1} {$x0},{$y0} {$ix0},{$iy0} {$ix0},{$iy1}",
                    'center' => "{$ix0},{$iy0} {$ix1},{$iy0} {$ix1},{$iy1} {$ix0},{$iy1}",
                ];
                $missing = isset($marks['ausente']) || isset($marks['edentulo_total']);
                $remnant = isset($marks['remanente_radicular']);
                $implant = $marks['implante'] ?? null;
                $outline = collect($marks)->except(['ausente', 'extraccion_indicada', 'corona', 'corona_temporal', 'tratamiento_pulpar', 'fractura', 'implante', 'remanente_radicular'])->last();
                $w = $shape['halfWidth'];
                $h = $shape['crownHeight'];
                // La pieza ocupa de su ápice a su borde oclusal; la X de ausente o de extracción la cruza entera.
                $toothTop = $upper ? $occlusalY - $shape['height'] : $occlusalY;
                $toothBottom = $upper ? $occlusalY : $occlusalY + $shape['height'];
                $center = $zones['center'];
                $clip = "{$uid}-c{$tooth}";
            @endphp

            <g class="odv-tooth"
                @if ($interactive) wire:key="t-{{ $odontogram->id }}-{{ $tooth }}" data-tooth-group="{{ $tooth }}" @endif
                @if ($flags['bad'] ?? false) data-bad @endif
                @if ($flags['good'] ?? false) data-good @endif
                @if ($flags['missing'] ?? false) data-missing @endif
                @if ($flags['today'] ?? false) data-today @endif
                @if (in_array($tooth, $preview, true)) data-preview @endif
            >
                <title>{{ $tooth }} · {{ Teeth::name($tooth) }}@if ($data['labels']): {{ collect($data['labels'])->map(fn ($label) => "{$label['text']} {$label['meaning']}")->implode(', ') }}@endif</title>
                <rect class="odv-col" x="{{ $cx - $W / 2 + 1 }}" y="{{ $rowTop + 1 }}" width="{{ $W - 2 }}" height="{{ $rowHeight - 2 }}" rx="8" />

                <rect class="odv-box" x="{{ $cx - $W / 2 + 4 }}" y="{{ $boxY }}" width="{{ $W - 8 }}" height="{{ $box }}" rx="3" />
                <text x="{{ $cx }}" y="{{ $boxY + 10.5 }}" class="odv-label">
                    @foreach (array_slice($data['labels'], 0, 2) as $i => $label)
                        <tspan fill="{{ $color($label['state']) }}">{{ $i ? ' ' : '' }}{{ $label['text'] }}</tspan>
                    @endforeach
                </text>

                <defs><clipPath id="{{ $clip }}"><path d="{{ $shape['crown'] }}" /></clipPath></defs>
                <g class="odv-anatomy{{ $missing ? ' is-missing' : '' }}" transform="translate({{ $cx }} {{ $occlusalY }}) scale({{ $sx }} {{ $sy }})">
                    @if ($implant)
                        <path d="{{ $shape['implant'] }}" fill="{{ $color($implant) }}" stroke="{{ $color($implant) }}" stroke-width=".8" />
                        @foreach ($shape['threads'] as [$ax, $ay, $bx, $by])
                            <line x1="{{ $ax }}" y1="{{ $ay }}" x2="{{ $bx }}" y2="{{ $by }}" stroke="#fff" stroke-width="1" />
                        @endforeach
                    @else
                        @if ($shape['palatal'])
                            <path class="odv-palatal" d="{{ $shape['palatal'] }}" />
                        @endif
                        <path class="odv-root" d="{{ $shape['roots'] }}" />
                    @endif
                    <path @class(['odv-crown', 'is-gone' => $remnant]) d="{{ $shape['crown'] }}" />

                    {{-- Las caras marcadas también se ven en la corona: mesial y distal a los lados, el borde y la vestibular. --}}
                    @unless ($remnant || $missing)
                        <g clip-path="url(#{{ $clip }})">
                            @if ($state = $surfaceState('M'))
                                <rect class="odv-on is-{{ $state }}" x="{{ $w * .55 }}" y="{{ -$h - 1 }}" width="{{ $w * .5 + 2 }}" height="{{ $h + 2 }}" />
                            @endif
                            @if ($state = $surfaceState('D'))
                                <rect class="odv-on is-{{ $state }}" x="{{ -$w - 2 }}" y="{{ -$h - 1 }}" width="{{ $w * .5 + 2 }}" height="{{ $h + 2 }}" />
                            @endif
                            @if ($state = $surfaceState($center))
                                <rect class="odv-on is-{{ $state }}" x="{{ -$w - 2 }}" y="{{ -$h * .22 }}" width="{{ 2 * $w + 4 }}" height="{{ $h * .22 + 2 }}" />
                            @endif
                            @if ($state = $surfaceState('V'))
                                <ellipse class="odv-on is-{{ $state }}" cx="0" cy="{{ -$h * .52 }}" rx="{{ $w * .42 }}" ry="{{ $h * .22 }}" />
                            @endif
                        </g>
                    @endunless

                    @isset($marks['tratamiento_pulpar'])
                        @foreach ($shape['canals'] as [$ax, $ay, $bx, $by])
                            <line x1="{{ $ax }}" y1="{{ $ay }}" x2="{{ $bx }}" y2="{{ $by }}" stroke="{{ $color($marks['tratamiento_pulpar']) }}" stroke-width="2.2" stroke-linecap="round" />
                        @endforeach
                    @endisset

                    @foreach (['corona', 'corona_temporal'] as $code)
                        @isset($marks[$code])
                            <ellipse cx="0" cy="{{ -$h / 2 }}" rx="{{ $w + 3.5 }}" ry="{{ $h / 2 + 3.5 }}" fill="none" stroke="{{ $color($marks[$code]) }}" stroke-width="2.2" />
                        @endisset
                    @endforeach

                    @isset($marks['fractura'])
                        <line x1="{{ -$w * .75 }}" y1="{{ -$h * .2 }}" x2="{{ $w * .65 }}" y2="{{ -$h * .85 }}" stroke="{{ $color($marks['fractura']) }}" stroke-width="2.2" stroke-linecap="round" />
                    @endisset
                </g>

                @foreach (['ausente', 'extraccion_indicada'] as $code)
                    @isset($marks[$code])
                        <g stroke="{{ $color($marks[$code]) }}" stroke-width="2.6" stroke-linecap="round">
                            <line x1="{{ $cx - $w - 1 }}" y1="{{ $toothTop + 2 }}" x2="{{ $cx + $w + 1 }}" y2="{{ $toothBottom - 1 }}" />
                            <line x1="{{ $cx + $w + 1 }}" y1="{{ $toothTop + 2 }}" x2="{{ $cx - $w - 1 }}" y2="{{ $toothBottom - 1 }}" />
                        </g>
                    @endisset
                @endforeach

                @foreach ($shapes as $zone => $points)
                    @php $surface = $zones[$zone]; @endphp
                    <polygon points="{{ $points }}" class="odv-surface is-{{ $data['surfaces'][$surface] ?? 'none' }}"
                        @if ($interactive) data-tooth="{{ $tooth }}" data-surface="{{ $surface }}" data-zone="{{ $zone }}" @endif
                    ><title>{{ $tooth }} {{ Teeth::surfaceName($surface) }}</title></polygon>
                @endforeach

                @if ($outline)
                    <rect x="{{ $x0 - 2 }}" y="{{ $y0 - 2 }}" width="{{ $D + 4 }}" height="{{ $D + 4 }}" fill="none" stroke="{{ $color($outline) }}" stroke-width="2" rx="2" />
                @endif

                <text x="{{ $cx }}" y="{{ $numY }}" class="odv-num">{{ $tooth }}</text>
                <circle class="odv-today" cx="{{ $cx + 13 }}" cy="{{ $numY - 4 }}" r="2.6" />
            </g>
        @endforeach

        @foreach ($rowSpans as $span)
            @php
                $from = min($positions[$span->tooth], $positions[$span->to_tooth]);
                $to = max($positions[$span->tooth], $positions[$span->to_tooth]);
                $stroke = $color($span->state->value);
                $code = $span->condition->code;
                $y = $crownLine;
            @endphp
            <g class="odv-span" data-span="{{ $span->state->value }}" @if (in_array($span->id, $today, true)) data-today @endif stroke="{{ $stroke }}" stroke-width="2.2" fill="none" stroke-linecap="round">
                @if ($code === 'ortodoncia_removible')
                    <polyline points="{{ collect(range(0, (int) floor(($to - $from + 16) / 6)))->map(fn ($i) => ($from - 8 + $i * 6).','.($y + ($i % 2 ? 3 : -3)))->implode(' ') }}" />
                @else
                    <line x1="{{ $from - 8 }}" y1="{{ $y }}" x2="{{ $to + 8 }}" y2="{{ $y }}" />
                    @if (in_array($code, ['protesis_removible', 'protesis_total'], true))
                        <line x1="{{ $from - 8 }}" y1="{{ $y + 4 * $sy }}" x2="{{ $to + 8 }}" y2="{{ $y + 4 * $sy }}" />
                    @endif
                @endif
                @if ($code === 'ortodoncia_fija')
                    @foreach ($positions->filter(fn ($x) => $x >= $from && $x <= $to) as $x)
                        <rect x="{{ $x - 4 }}" y="{{ $y - 4 }}" width="8" height="8" fill="#fff" />
                        <line x1="{{ $x - 2.5 }}" y1="{{ $y }}" x2="{{ $x + 2.5 }}" y2="{{ $y }}" stroke-width="1.4" />
                        <line x1="{{ $x }}" y1="{{ $y - 2.5 }}" x2="{{ $x }}" y2="{{ $y + 2.5 }}" stroke-width="1.4" />
                    @endforeach
                @elseif ($code !== 'ortodoncia_removible')
                    <line x1="{{ $from - 8 }}" y1="{{ $y - 5 }}" x2="{{ $from - 8 }}" y2="{{ $y + 5 }}" />
                    <line x1="{{ $to + 8 }}" y1="{{ $y - 5 }}" x2="{{ $to + 8 }}" y2="{{ $y + 5 }}" />
                @endif
            </g>
            <text x="{{ ($from + $to) / 2 }}" y="{{ $y - $sy * 6 + ($upper ? 0 : 3) }}" class="odv-label odv-span-label" data-span="{{ $span->state->value }}" @if (in_array($span->id, $today, true)) data-today @endif fill="{{ $stroke }}" style="paint-order: stroke; stroke: #fff; stroke-width: 3px">{{ OdontogramChart::label($span) }}</text>
        @endforeach
    @endforeach
</svg>
