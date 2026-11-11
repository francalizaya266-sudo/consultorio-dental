@use('App\Services\Dashboard\DayMoment')
@use('App\Support\Money')
{{-- Lo que toca según la hora: antes de abrir, confirmar; al terminar, cerrar el día. --}}
<x-filament-widgets::widget>
    @if ($moment === DayMoment::BEFORE)
        <x-filament::section heading="Antes de abrir" icon="heroicon-o-sun" class="day-moment">
            @if ($unconfirmed->isNotEmpty())
                <h3 class="cc-title">{{ $unconfirmed->count() === 1 ? '1 cita de hoy sin confirmar' : $unconfirmed->count().' citas de hoy sin confirmar' }}</h3>
                <ul class="cc-rows">
                    @foreach ($unconfirmed as $row)
                        <li>
                            <span class="cc-row-text">
                                <strong>{{ $row['time'] }} · {{ $row['name'] }}</strong>
                                <small>{{ $row['detail'] }}</small>
                            </span>
                            <span class="cc-row-actions">
                                @if (($confirm = ($this->confirmAction)(['appointment' => $row['id']]))->isVisible())
                                    {{ $confirm }}
                                @endif
                                <x-filament::link :href="$row['url']" size="sm" color="gray">Ver</x-filament::link>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($notices > 0)
                <div class="cc-status">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="cc-status-icon" />
                    <span class="cc-row-text">
                        <strong>{{ $notices === 1 ? '1 aviso por enviar' : $notices.' avisos por enviar' }}</strong>
                        <small>Van primero los de las citas más próximas.</small>
                    </span>
                    <x-filament::button size="sm" tag="a" :href="$noticesUrl">Ver avisos</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @elseif ($moment === DayMoment::CLOSING)
        <x-filament::section heading="Para cerrar el día" icon="heroicon-o-moon" class="day-moment">
            @if ($pending->isNotEmpty())
                <h3 class="cc-title">Se fueron debiendo <span class="cc-title-amount">{{ Money::format($pendingTotal) }}</span></h3>
                <ul class="cc-rows">
                    @foreach ($pending as $row)
                        <li>
                            <span class="cc-row-text">
                                <strong>{{ $row['patient']->full_name }}</strong>
                                <small>{{ $row['concepts'] }}</small>
                            </span>
                            <span class="cc-amount">{{ Money::format($row['due']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($unclosed->isNotEmpty())
                <h3 class="cc-title">Citas sin cerrar: ¿vino o no vino?</h3>
                <ul class="cc-rows">
                    @foreach ($unclosed as $row)
                        <li>
                            <span class="cc-row-text">
                                <strong>{{ $row['time'] }} · {{ $row['name'] }}</strong>
                                <small>{{ $row['detail'] }}</small>
                            </span>
                            <span class="cc-row-actions">
                                {{-- Una acción oculta se pinta apagada: solo va la que aplica a esa cita. --}}
                                @foreach ([$this->completeAction, $this->missedAction] as $action)
                                    @if (($action = $action(['appointment' => $row['id']]))->isVisible())
                                        {{ $action }}
                                    @endif
                                @endforeach
                                <x-filament::link :href="$row['url']" size="sm" color="gray">Ver</x-filament::link>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div @class(['cc-status', 'is-closed' => $record])>
                @if ($record)
                    <x-filament::icon icon="heroicon-o-lock-closed" class="cc-status-icon" />
                    <span class="cc-row-text">
                        <strong>Caja cerrada a las {{ $record->created_at->format('H:i') }}</strong>
                        <small>Quedó algo pendiente: revísalo mañana a primera hora.</small>
                    </span>
                @else
                    <x-filament::icon icon="heroicon-o-calculator" class="cc-status-icon" />
                    <span class="cc-row-text">
                        <strong>Falta el cierre de caja</strong>
                        <small>Ahí se cobra a quien se fue debiendo y se cuenta el efectivo.</small>
                    </span>
                    <x-filament::button size="sm" tag="a" :href="$closingUrl">Cerrar la caja</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-widgets::widget>
