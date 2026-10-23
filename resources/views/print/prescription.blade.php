<x-print-layout title="Receta" size="A5" :doctor="$doctor" :patient="$patient" :date="$prescription->issued_at">
    <style>
        .rp { font-size: 1.6rem; font-weight: 800; font-style: italic; color: var(--brand); margin-bottom: 6px; }
        ol { margin: 0; padding-left: 22px; }
        li { margin-bottom: 10px; }
        li strong { display: block; font-size: 1rem; }
        .indications { margin-top: 18px; }
        .indications p { margin: 0; white-space: pre-line; }
        .indications h2 { font-size: .8rem; text-transform: uppercase; color: var(--muted); margin: 0 0 4px; }
    </style>

    <div class="rp">Rp.</div>
    <ol>
        @foreach ($items as $item)
            <li>
                <strong>{{ $item['medication'] }}</strong>
                {{ $item['instructions'] }}
            </li>
        @endforeach
    </ol>

    @if (filled($prescription->indications))
        <div class="indications">
            <h2>Indicaciones</h2>
            <p>{{ $prescription->indications }}</p>
        </div>
    @endif

    <x-slot:footer>
        <div class="signatures">
            <div class="signature">
                {{ $doctor->name }}<br>
                <span class="muted">{{ $doctor->cop_number ? 'C.O.P. '.$doctor->cop_number : 'Firma y sello' }}</span>
            </div>
        </div>
    </x-slot:footer>
</x-print-layout>
