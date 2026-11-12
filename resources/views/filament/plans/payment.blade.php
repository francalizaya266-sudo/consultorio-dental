@use('App\Support\Money')
{{-- El pago de un plan aceptado: cuánto suma, cuánto lleva pagado, lo que ya debe y lo que falta; debajo, lo que firmó y su cronograma. --}}
<div class="plan-money">
    <dl class="plan-money-figures">
        <div>
            <dt>Total aceptado</dt>
            <dd>{{ Money::format($progress['total']) }}</dd>
        </div>
        <div>
            <dt>Pagó</dt>
            <dd @class(['is-paid' => $progress['paid'] > 0])>{{ Money::format($progress['paid']) }}</dd>
        </div>
        @if ($progress['owed'] > 0)
            <div>
                <dt>{{ $progress['late'] ? 'Debe (atrasado)' : 'Debe' }}</dt>
                <dd @class(['is-late' => $progress['late'], 'is-due' => ! $progress['late']])>{{ Money::format($progress['owed']) }}</dd>
            </div>
        @endif
        <div>
            <dt>Falta pagar</dt>
            <dd>{{ $progress['remaining'] > 0 ? Money::format($progress['remaining']) : 'Nada' }}</dd>
        </div>
    </dl>

    @include('filament.clinical.plan-schedule', ['paymentText' => $paymentText, 'states' => $states, 'signatures' => $signatures])
</div>
