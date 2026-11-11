@use('App\Enums\PlanItemStatus')
@use('App\Support\Money')
{{-- Su plan de tratamiento, como está hoy: cada procedimiento con su precio y en qué va. --}}
<div class="visit-plans">
    @foreach ($plans as $plan)
        @php
            $items = $plan->items->reject(fn ($item) => $item->status === PlanItemStatus::Cancelled);
            $done = $items->where('status', PlanItemStatus::Done)->count();
        @endphp
        <div class="visit-plan" data-plan="{{ $plan->getKey() }}">
            <div class="visit-plan-head">
                @if (filled($plan->title) && $plan->title !== 'Plan de tratamiento')
                    <strong>{{ $plan->title }}</strong>
                @endif
                <x-filament::badge :color="$plan->status->getColor()" size="sm">{{ $plan->status->getLabel() }}</x-filament::badge>
            </div>
            <ul class="visit-plan-items">
                @foreach ($items as $item)
                    <li @class(['is-done' => $item->status === PlanItemStatus::Done])>
                        <span class="visit-plan-name">{{ $item->label() }}@if ($item->quantity > 1) × {{ $item->quantity }}@endif</span>
                        <span class="visit-plan-price">{{ Money::format($item->subtotal()) }}</span>
                        <span class="visit-plan-state">{{ collect([
                            $item->status !== PlanItemStatus::Done && $item->sessions_planned > 1
                                ? 'Sesión '.min($item->sessions_done + 1, $item->sessions_planned).' de '.$item->sessions_planned
                                : $item->status->getLabel(),
                            $plan->accepted_at !== null && $item->accepted_at === null && $item->status !== PlanItemStatus::Done ? 'falta que lo acepte' : null,
                        ])->filter()->implode(' · ') }}</span>
                    </li>
                @endforeach
            </ul>
            @if (($options = $paymentOptions[$plan->getKey()] ?? []) !== [])
                <div class="visit-plan-options">
                    <span>Formas de pago, se elige al firmar:</span>
                    <ul>
                        @foreach ($options as $option)
                            <li>{{ $option }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="visit-plan-foot">
                <span>Total <strong>{{ Money::format($plan->total()) }}</strong> · {{ $done }} de {{ $items->count() }} hechos</span>
                @if ($url)
                    <x-filament::link :href="$url" size="sm">Ver en su historia</x-filament::link>
                @endif
            </div>
        </div>
    @endforeach
</div>
