{{-- Las cuentas de esta cita, y aparte, el saldo de toda la vida del paciente (para no confundir uno con otro). --}}
<div class="ap-billing">
    <div class="ap-money">
        <div class="ap-money-tile">
            <span class="ap-money-label">Se cargó en esta cita</span>
            <strong class="ap-money-value">{{ \App\Support\Money::format($charged) }}</strong>
            <ul class="ap-money-lines">
                @forelse ($charges as $charge)
                    <li>
                        <span>{{ $charge['label'] }}@if ($charge['note']) <small>({{ $charge['note'] }})</small>@endif</span>
                        <span>{{ $charge['amount'] }}</span>
                    </li>
                @empty
                    <li><span>Nada</span></li>
                @endforelse
            </ul>
        </div>

        <div class="ap-money-tile">
            <span class="ap-money-label">Pagó en esta cita</span>
            <strong class="ap-money-value">{{ \App\Support\Money::format($paid) }}</strong>
            <ul class="ap-money-lines">
                @forelse ($payments as $payment)
                    <li>
                        <span>{{ $payment['label'] }}</span>
                        <a href="{{ $payment['url'] }}" target="_blank" rel="noopener">{{ $payment['amount'] }} · constancia</a>
                    </li>
                @empty
                    <li><span>Aún no paga</span></li>
                @endforelse
            </ul>
        </div>

        <div @class(['ap-money-tile', 'is-due' => $due > 0, 'is-paid' => $due <= 0])>
            <span class="ap-money-label">{{ $due > 0 ? 'Falta de esta cita' : 'Esta cita' }}</span>
            <strong class="ap-money-value">{{ $due > 0 ? \App\Support\Money::format($due) : 'Pagada' }}</strong>
            <div class="ap-money-bar" role="img" aria-label="Pagado el {{ $percent }} % de esta cita"><i style="width: {{ $percent }}%"></i></div>
            <small class="ap-money-hint">Pagado el {{ $percent }} % de lo cargado</small>
        </div>
    </div>

    <p @class(['ap-balance', 'is-owing' => $balance > 0, 'is-clear' => $balance <= 0])>
        <span>
            <strong>Saldo total del paciente: {{ $balance_label }}</strong>
            <small>Suma todas sus citas, cuotas y pagos, no solo esta cita.</small>
        </span>
        @if ($account_url)
            <x-filament::link :href="$account_url" size="sm" icon="heroicon-m-arrow-right" icon-position="after">Ver su cuenta</x-filament::link>
        @endif
    </p>
</div>
