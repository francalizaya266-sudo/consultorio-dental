@use('App\Support\Money')
@php($refund = (float) $payment->amount < 0)
<x-print-layout :title="$refund ? 'Constancia de devolución' : 'Constancia de pago'" size="A5" :patient="$patient" :date="$payment->paid_at" :details="['Constancia' => $number]" :auto-print="false">
    @if ($whatsappUrl)
        <x-slot:actions>
            <form method="POST" action="{{ $whatsappUrl }}" target="_blank">
                @csrf
                <button type="submit" class="secondary">Enviar por WhatsApp</button>
            </form>
        </x-slot:actions>
    @endif
    <style>
        .voided { margin-bottom: 12px; padding: 8px 10px; border: 2px solid #b91c1c; border-radius: 6px; color: #b91c1c; font-weight: 800; text-align: center; }
        .voided span { display: block; font-weight: 400; font-size: .85rem; }
        tfoot td { font-weight: 800; font-size: 1rem; border-bottom: 0; border-top: 2px solid var(--ink); }
        .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 6px 16px; margin-top: 14px; }
        .facts span { display: block; font-size: .75rem; color: var(--muted); }
        .balance { margin-top: 14px; padding: 8px 10px; background: #f1f5f9; border-radius: 6px; font-weight: 700; }
        .fine { margin-top: 16px; font-size: .75rem; color: var(--muted); }
    </style>

    @if ($payment->voided_at)
        <div class="voided">
            ANULADO
            <span>El {{ $payment->voided_at->format('d/m/Y H:i') }}{{ $payment->voidedBy ? ' por '.$payment->voidedBy->name : '' }}{{ $payment->void_reason ? ': '.$payment->void_reason : '' }}</span>
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="num">Monto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ Money::format(abs($line['amount'])) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>{{ $refund ? 'Total devuelto' : 'Total pagado' }}</td>
                <td class="num">{{ Money::format(abs((float) $payment->amount)) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="facts">
        <div><span>Fecha y hora</span>{{ $payment->paid_at->format('d/m/Y H:i') }}</div>
        <div><span>Medio</span>{{ $payment->method->getLabel() }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</div>
        <div><span>{{ $refund ? 'Entregó' : 'Recibió' }}</span>{{ $payment->receivedBy?->name ?? '—' }}</div>
    </div>

    @if ($balance !== null)
        <div class="balance">Saldo después de este pago: {{ Money::balance($balance) }}</div>
    @endif

    <x-slot:footer>
        <p class="fine">Constancia interna del consultorio: no reemplaza la boleta ni la factura.</p>
    </x-slot:footer>
</x-print-layout>
