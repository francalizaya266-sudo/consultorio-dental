@use('App\Support\Money')
{{-- Cronograma de pagos del plan con el estado de cada pago, y las firmas del paciente. --}}
@php
    $labels = ['paid' => 'Pagado', 'due' => 'Vence hoy', 'late' => 'Atrasado', 'upcoming' => 'Por vencer'];
    $colors = ['paid' => 'var(--success-600)', 'due' => 'var(--warning-600)', 'late' => 'var(--danger-600)', 'upcoming' => 'var(--gray-500)'];
@endphp
<div style="display: grid; gap: 1rem; font-size: .9rem">
    <p style="font-weight: 600">{{ $paymentText }}</p>

    @if ($states !== [])
        <table style="width: 100%; border-collapse: collapse">
            <thead>
                <tr style="text-align: left; color: var(--gray-500); font-size: .8rem">
                    <th style="padding: .35rem .25rem">Pago</th>
                    <th style="padding: .35rem .25rem">Vence</th>
                    <th style="padding: .35rem .25rem; text-align: right">Monto</th>
                    <th style="padding: .35rem .25rem">Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($states as $row)
                    <tr style="border-top: 1px solid var(--gray-200)">
                        <td style="padding: .35rem .25rem">{{ $row['installment']->label() }}</td>
                        <td style="padding: .35rem .25rem">{{ $row['installment']->due_on->format('d/m/Y') }}</td>
                        <td style="padding: .35rem .25rem; text-align: right; font-variant-numeric: tabular-nums">{{ Money::format($row['installment']->net()) }}</td>
                        <td style="padding: .35rem .25rem; font-weight: 600; color: {{ $colors[$row['state']] }}">
                            {{ $labels[$row['state']] }}@if (in_array($row['state'], ['due', 'late'], true) && $row['due'] < $row['installment']->net()) · falta {{ Money::format($row['due']) }}@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($signatures->isNotEmpty())
        <div>
            <p style="font-weight: 600; margin-bottom: .25rem">Firmas</p>
            <ul style="list-style: disc; padding-left: 1.25rem; color: var(--gray-600)">
                @foreach ($signatures as $signature)
                    <li>{{ $signature->signed_at->format('d/m/Y H:i') }} · {{ $signature->signer_name }} · {{ Money::format($signature->total) }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
