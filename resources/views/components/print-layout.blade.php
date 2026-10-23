@props(['title', 'size' => 'A4', 'doctor' => null, 'patient' => null, 'date' => null])
@php
    $clinic = \App\Models\Setting::get('clinic.name');
    $contact = collect([\App\Models\Setting::get('clinic.address'), \App\Models\Setting::get('clinic.phone')])->filter()->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}{{ $patient ? ' · '.$patient->full_name : '' }}</title>
    <style>
        @page { size: {{ $size }}; margin: 12mm; }
        :root { --brand: #0f766e; --ink: #0f172a; --muted: #475569; --line: #cbd5e1; --gray-600: #4b5563; --primary-100: #ccfbf1; }
        * { box-sizing: border-box; }
        /* Que salgan los colores (puntos rojo / azul, fondos) también al imprimir. */
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #e2e8f0; }
        .sheet { background: #fff; margin: 24px auto; padding: 14mm; max-width: {{ $size === 'A5' ? '148mm' : '210mm' }}; min-height: {{ $size === 'A5' ? '210mm' : '297mm' }}; box-shadow: 0 4px 24px rgb(15 23 42 / .12); display: flex; flex-direction: column; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid var(--brand); padding-bottom: 10px; margin-bottom: 14px; }
        .clinic { font-size: 1.25rem; font-weight: 800; color: var(--brand); }
        .muted { color: var(--muted); font-size: .85rem; }
        .doctor { text-align: right; }
        .doctor strong { display: block; }
        h1 { font-size: 1.1rem; margin: 0 0 10px; text-transform: uppercase; letter-spacing: .04em; }
        .patient { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 4px 16px; padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-bottom: 16px; }
        .patient span { display: block; font-size: .75rem; color: var(--muted); }
        main { flex: 1; }
        footer { margin-top: 24px; }
        .signatures { display: flex; justify-content: space-around; gap: 24px; margin-top: 48px; }
        .signature { min-width: 180px; text-align: center; border-top: 1px solid var(--ink); padding-top: 4px; font-size: .85rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { font-size: .75rem; text-transform: uppercase; color: var(--muted); }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .toolbar { position: sticky; top: 0; display: flex; justify-content: center; gap: 12px; padding: 12px; background: #0f172a; }
        .toolbar button { font: inherit; font-weight: 700; padding: 10px 18px; border-radius: 8px; border: 0; cursor: pointer; background: #14b8a6; color: #042f2e; }
        .toolbar button.secondary { background: #334155; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; min-height: auto; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Imprimir o guardar PDF</button>
    <button type="button" class="secondary" onclick="window.close()">Cerrar</button>
</div>
<div class="sheet">
    <header>
        <div>
            <div class="clinic">{{ $clinic }}</div>
            @if ($contact)
                <div class="muted">{{ $contact }}</div>
            @endif
        </div>
        @if ($doctor)
        <div class="doctor">
            <strong>{{ $doctor->name }}</strong>
            @if ($doctor->specialty)
                <div class="muted">{{ $doctor->specialty }}</div>
            @endif
            <div class="muted">
                {{ collect([$doctor->cop_number ? 'C.O.P. '.$doctor->cop_number : null, $doctor->rne_number ? 'R.N.E. '.$doctor->rne_number : null])->filter()->implode(' · ') }}
            </div>
        </div>
        @endif
    </header>

    <h1>{{ $title }}</h1>

    @if ($patient)
    <div class="patient">
        <div><span>Paciente</span>{{ $patient->full_name }}</div>
        @if ($patient->age !== null)
            <div><span>Edad</span>{{ $patient->age }} años</div>
        @endif
        @if ($patient->document_number)
            <div><span>{{ $patient->document_type?->getLabel() ?? 'Documento' }}</span>{{ $patient->document_number }}</div>
        @endif
        <div><span>Fecha</span>{{ ($date ?? now())->format('d/m/Y') }}</div>
    </div>
    @endif

    <main>{{ $slot }}</main>

    <footer>{{ $footer ?? '' }}</footer>
</div>
<script>
    // Abrir la hoja ya es pedir imprimirla: se muestra el diálogo de una vez.
    window.addEventListener('load', () => setTimeout(() => window.print(), 300));
</script>
</body>
</html>
