@php
    use App\Support\Money;
@endphp
<style>
    .appt-panel { display: grid; gap: 1rem; font-size: .875rem; color: var(--gray-700); }
    .dark .appt-panel { color: var(--gray-300); }
    .appt-panel-chips { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; }
    .appt-panel-alert { border-radius: .6rem; padding: .6rem .75rem; background: var(--danger-50); color: var(--danger-700); font-weight: 600; }
    .dark .appt-panel-alert { background: rgb(239 68 68 / .12); color: var(--danger-300); }
    .appt-panel dl { display: grid; grid-template-columns: max-content 1fr; gap: .45rem 1rem; margin: 0; }
    .appt-panel dt { color: var(--gray-500); }
    .appt-panel dd { margin: 0; color: var(--gray-950); font-weight: 500; overflow-wrap: anywhere; }
    .dark .appt-panel dd { color: #fff; }
    .appt-panel dd a { color: var(--primary-600); text-decoration: none; }
    .dark .appt-panel dd a { color: var(--primary-400); }
    .appt-panel-box { border: 1px solid var(--gray-200); border-radius: .75rem; padding: .75rem; display: grid; gap: .4rem; }
    .dark .appt-panel-box { border-color: rgb(255 255 255 / .1); }
    .appt-panel-box h3 { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--gray-500); margin: 0; }
    .appt-panel-box ul { margin: 0; padding-left: 1rem; list-style: disc; }
    .appt-panel-total { display: flex; justify-content: space-between; font-weight: 700; color: var(--gray-950); font-size: 1rem; }
    .dark .appt-panel-total { color: #fff; }
    .appt-panel-owes { color: var(--danger-600); font-weight: 700; }
    .appt-panel-ok { color: var(--success-600); font-weight: 600; }
    .appt-panel-links { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; }
</style>

<div class="appt-panel">
    <div class="appt-panel-chips">
        <x-filament::badge :color="$appointment->status->getColor()">{{ $appointment->status->getLabel() }}</x-filament::badge>
        @if ($appointment->needsCall())
            <x-filament::badge color="danger" icon="heroicon-m-phone">Sin confirmar: llamar</x-filament::badge>
        @endif
        @if ($appointment->is_new_patient)
            <x-filament::badge color="info">Paciente nuevo</x-filament::badge>
        @endif
        @if ($balance !== null && $balance > 0)
            <x-filament::badge color="danger">Debe {{ Money::short($balance) }}</x-filament::badge>
        @endif
    </div>

    @if ($alerts)
        <div class="appt-panel-alert" role="alert">⚠ {{ implode(' · ', $alerts) }}</div>
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
