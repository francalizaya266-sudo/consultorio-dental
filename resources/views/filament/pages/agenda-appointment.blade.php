@php
    use App\Support\Money;
@endphp

<div class="appt-panel">
    <div class="appt-panel-chips">
        <x-filament::badge :color="$appointment->status->getColor()">{{ $appointment->status->getLabel() }}</x-filament::badge>
        @if ($appointment->isUnclosed())
            <x-filament::badge color="danger" icon="heroicon-m-exclamation-triangle">Sin cerrar: {{ $appointment->status->isChangeable() ? 'marca si vino' : 'falta cerrar su atención' }}</x-filament::badge>
        @elseif ($appointment->needsCall())
            <x-filament::badge color="danger" icon="heroicon-m-phone">Por confirmar: llamar</x-filament::badge>
        @endif
        @if ($block)
            <x-filament::badge color="danger" icon="heroicon-m-no-symbol">En bloqueo: muévela</x-filament::badge>
        @endif
        @if ($appointment->is_new_patient)
            <x-filament::badge color="info">Paciente nuevo</x-filament::badge>
        @endif
        @if ($balance !== null && $balance > 0)
            <x-filament::badge color="warning">Debe {{ Money::short($balance) }}</x-filament::badge>
        @endif
    </div>

    @if ($block)
        <div class="appt-panel-alert is-caution" role="alert">Ese horario quedó bloqueado por "{{ $block->reason ?: $block->type->getLabel() }}" con esta cita ya dada. En "Más" usa "Mover cita": al paciente le queda listo el aviso del cambio.</div>
    @endif

    @if ($alerts)
        <div @class(['appt-panel-alert', 'is-caution' => ! $alertsSevere]) role="alert">{{ $alertsSevere ? '⚠ ' : '' }}{{ implode(' · ', $alerts) }}</div>
    @endif

    <dl>
        <dt>Tratamiento</dt>
        <dd>{{ $appointment->treatment->name }}</dd>
        <dt>Doctor</dt>
        <dd>{{ $appointment->doctor->name }}</dd>
        @if ($phone)
            <dt>Celular</dt>
            <dd><a href="{{ $phoneLink }}">{{ $phone }}</a></dd>
        @endif
        @if (filled($appointment->patient_note))
            <dt>Nota del paciente</dt>
            <dd>{{ $appointment->patient_note }}</dd>
        @endif
        @if (filled($appointment->internal_notes))
            <dt>Notas internas</dt>
            <dd>{{ $appointment->internal_notes }}</dd>
        @endif
        @if ($balance !== null)
            <dt>Saldo</dt>
            <dd @class(['appt-panel-owes' => $balance > 0, 'appt-panel-ok' => $balance <= 0])>{{ Money::balance($balance) }}</dd>
        @endif
    </dl>

    @if ($toCharge && $toCharge['lines'] !== [])
        <div class="appt-panel-box">
            <h3>Para cobrar ese día</h3>
            <ul>
                @foreach ($toCharge['lines'] as $line)
                    <li>{{ $line['label'] }}@if ($line['amount'] !== null): {{ Money::format($line['amount']) }}@endif</li>
                @endforeach
            </ul>
            <div class="appt-panel-total"><span>Cobrar</span><span>{{ Money::format($toCharge['total']) }}</span></div>
        </div>
    @endif

    <div class="appt-panel-links">
        <x-filament::link :href="$patientUrl" icon="heroicon-m-user">Ficha del paciente</x-filament::link>
        <x-filament::link :href="$appointmentUrl" icon="heroicon-m-arrow-top-right-on-square">Ver todo de la cita</x-filament::link>
    </div>
</div>
