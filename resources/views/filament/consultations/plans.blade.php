@use('App\Enums\PlanItemStatus')
@use('App\Models\TreatmentPlan')
@use('App\Support\Money')
{{-- Su plan de tratamiento, como está hoy: cada procedimiento (o cada paquete, con lo que incluye) con su precio y en qué va. --}}
<div class="visit-plans">
    @foreach ($plans as $plan)
        @php
            $items = $plan->items->reject(fn ($item) => $item->status === PlanItemStatus::Cancelled);
            $done = $items->where('status', PlanItemStatus::Done)->count();
            $state = fn ($item) => collect([
                $item->status !== PlanItemStatus::Done && $item->sessions_planned > 1
                    ? 'Sesión '.min($item->sessions_done + 1, $item->sessions_planned).' de '.$item->sessions_planned
                    : $item->status->getLabel(),
                $plan->accepted_at !== null && $item->accepted_at === null && $item->status !== PlanItemStatus::Done ? 'falta que lo acepte' : null,
            ])->filter()->implode(' · ');
        @endphp
        <div class="visit-plan" data-plan="{{ $plan->getKey() }}">
            <div class="visit-plan-head">
                @if (filled($plan->title) && $plan->title !== 'Plan de tratamiento')
                    <strong>{{ $plan->title }}</strong>
                @endif
                <x-filament::badge :color="$plan->status->getColor()" size="sm">{{ $plan->status->getLabel() }}</x-filament::badge>
            </div>
            <ul class="visit-plan-items">
                @foreach (TreatmentPlan::lines($items) as $line)
                    @if ($line['package'])
                        <li class="visit-plan-package">
                            <span class="visit-plan-name">{{ $line['label'] }}</span>
                            <span class="visit-plan-price">{{ Money::format($line['price']) }}</span>
                            <ul class="visit-plan-package-items">
                                @foreach ($line['items'] as $item)
                                    <li @class(['is-done' => $item->status === PlanItemStatus::Done])>
                                        <span class="visit-plan-name">{{ $item->label() }}@if ($item->quantity > 1) × {{ $item->quantity }}@endif</span>
                                        <span class="visit-plan-state">{{ $state($item) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @else
                        @php($item = $line['items']->first())
                        <li @class(['is-done' => $item->status === PlanItemStatus::Done])>
                            <span class="visit-plan-name">{{ $line['label'] }}</span>
                            <span class="visit-plan-price">{{ Money::format($line['price']) }}</span>
                            <span class="visit-plan-state">{{ $state($item) }}</span>
                        </li>
                    @endif
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
