@use('App\Support\Money')
<x-print-layout title="Plan de tratamiento y presupuesto" :doctor="$doctor" :patient="$patient" :date="$plan->created_at">
    <style>
        .plan-title { font-weight: 700; margin-bottom: 8px; }
        tfoot td { font-weight: 800; font-size: 1rem; border-bottom: 0; }
        .notes { margin-top: 16px; white-space: pre-line; }
        .fine { margin-top: 16px; font-size: .8rem; color: var(--muted); }
        .payment { margin-top: 16px; }
        .package { margin: 10px 0 0; }
        .payment h3 { font-size: .95rem; margin: 0 0 6px; }
        .tag { font-size: .75rem; font-weight: 700; color: #b45309; }
        .signed { display: flex; justify-content: space-around; gap: 24px; margin-top: 32px; align-items: flex-end; flex-wrap: wrap; }
        .signed figure { margin: 0; text-align: center; min-width: 220px; }
        .signed img { display: block; max-width: 240px; max-height: 110px; margin: 0 auto -6px; }
        .signed figcaption { border-top: 1px solid var(--ink); padding-top: 4px; font-size: .85rem; }
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
                        @if ($item->sessions_planned > 1 || $item->package)
                            <div class="muted">{{ collect([$item->sessions_planned > 1 ? $item->sessions_planned.' sesiones' : null, $item->package ? 'Paquete '.$item->package->name : null])->filter()->implode(' · ') }}</div>
                        @endif
                        @if ($plan->accepted_at && ! $item->accepted_at)
                            <div class="tag">Agregado: falta la firma del paciente</div>
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

    @foreach ($packages as $package)
        <p class="package">
            <strong>Paquete {{ $package['name'] }}:</strong> precio normal {{ Money::format($package['normal']) }}, en el paquete {{ Money::format($package['price']) }}.
            @if ($package['savings'] > 0)
                <strong>Ahorra {{ Money::format($package['savings']) }}.</strong>
            @endif
        </p>
    @endforeach

    @if ($companions)
        <p class="package"><strong>2x1:</strong> con este plan se atiende también a {{ $companions }}, sin costo.</p>
    @endif

    @if ($payer)
        <p class="package"><strong>Incluido en el 2x1 de {{ $payer }}:</strong> lo que viene del paquete no tiene costo.</p>
    @endif

    @if ($packageOptions !== [])
        <div class="payment">
            <h3>Formas de pago del paquete</h3>
            @foreach ($packageOptions as $option)
                <p>{{ $option }}</p>
            @endforeach
        </div>
    @endif

    @if ($paymentText)
        <div class="payment">
            <h3>Forma de pago</h3>
            <p>{{ $paymentText }}</p>
            @if ($plan->installments->isNotEmpty())
                <table>
                    <thead>
                        <tr>
                            <th>Pago</th>
                            <th>Vence</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plan->installments as $installment)
                            <tr>
                                <td>{{ $installment->label() }}</td>
                                <td>{{ $installment->due_on->format('d/m/Y') }}</td>
                                <td class="num">{{ Money::format($installment->net()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @if (filled($plan->notes))
        <p class="notes">{{ $plan->notes }}</p>
    @endif

    <p class="fine">
        @if ($plan->accepted_at)
            Precio acordado el {{ $plan->accepted_at->format('d/m/Y') }}: no cambia aunque suba el precio de lista. Lo que se agregue después se firma aparte.
        @else
            Presupuesto referencial. Si durante el tratamiento cambia el diagnóstico, se informará al paciente antes de continuar.
        @endif
    </p>

    <x-slot:footer>
        @if ($signatures->isNotEmpty())
            <div class="signed">
                @foreach ($signatures as ['signature' => $signature, 'image' => $image])
                    <figure>
                        @if ($image)
                            <img src="{{ $image }}" alt="Firma de {{ $signature->signer_name }}">
                        @endif
                        <figcaption>
                            {{ $signature->signer_name }}{{ $signature->signer_document ? ' · '.$signature->signer_document : '' }}<br>
                            <span class="muted">{{ $loop->first ? 'Aceptó el plan' : 'Aceptó lo agregado' }} · {{ Money::format($signature->total) }} · {{ $signature->signed_at->format('d/m/Y H:i') }}</span>
                        </figcaption>
                    </figure>
                @endforeach
                <figure>
                    <figcaption>{{ $doctor->name }}<br><span class="muted">{{ $doctor->cop_number ? 'C.O.P. '.$doctor->cop_number : 'Firma y sello' }}</span></figcaption>
                </figure>
            </div>
        @else
            <div class="signatures">
                <div class="signature">Paciente o apoderado<br><span class="muted">Acepto el plan</span></div>
                <div class="signature">{{ $doctor->name }}<br><span class="muted">{{ $doctor->cop_number ? 'C.O.P. '.$doctor->cop_number : 'Firma y sello' }}</span></div>
            </div>
        @endif
    </x-slot:footer>
</x-print-layout>
