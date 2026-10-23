@use('App\Support\Money')
<x-print-layout title="Plan de tratamiento y presupuesto" :doctor="$doctor" :patient="$patient" :date="$plan->created_at">
    <style>
        .plan-title { font-weight: 700; margin-bottom: 8px; }
        tfoot td { font-weight: 800; font-size: 1rem; border-bottom: 0; }
        .notes { margin-top: 16px; white-space: pre-line; }
        .fine { margin-top: 16px; font-size: .8rem; color: var(--muted); }
    </style>

    @if ($plan->title)
        <div class="plan-title">{{ $plan->title }}</div>
    @endif

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Procedimiento</th>
                <th>Pieza</th>
                <th class="num">Cant.</th>
                <th class="num">Precio</th>
                <th class="num">Desc.</th>
                <th class="num">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ $item->description }}
                        @if ($item->sessions_planned > 1)
                            <div class="muted">{{ $item->sessions_planned }} sesiones</div>
                        @endif
                    </td>
                    <td>{{ collect([$item->tooth, $item->surfaces])->filter()->implode(' ') ?: '—' }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ Money::format($item->unit_price) }}</td>
                    <td class="num">{{ (float) $item->discount > 0 ? '− '.Money::format($item->discount) : '—' }}</td>
                    <td class="num">{{ Money::format($item->subtotal()) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="num">Total</td>
                <td class="num">{{ Money::format($plan->total()) }}</td>
            </tr>
        </tfoot>
    </table>

    @if (filled($plan->notes))
        <p class="notes">{{ $plan->notes }}</p>
    @endif

    <p class="fine">
        Presupuesto referencial. Si durante el tratamiento cambia el diagnóstico, se informará al paciente antes de continuar.
    </p>

    <x-slot:footer>
        <div class="signatures">
            <div class="signature">Paciente o apoderado<br><span class="muted">Acepto el plan</span></div>
            <div class="signature">{{ $doctor->name }}<br><span class="muted">{{ $doctor->cop_number ? 'C.O.P. '.$doctor->cop_number : 'Firma y sello' }}</span></div>
        </div>
    </x-slot:footer>
</x-print-layout>
