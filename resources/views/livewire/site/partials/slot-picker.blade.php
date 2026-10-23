{{-- Tira de días con horarios libres y los horarios del día elegido. --}}
@php
    use App\Support\Dates;
    use Carbon\CarbonImmutable;

    $groups = $this->visibleSlots->groupBy(fn (array $slot) => $slot['time'] < '12:00' ? 'Por la mañana' : 'Por la tarde');
@endphp
@if ($availableDays === [])
    <div class="panel">
        <p>No encontramos horarios libres en las próximas semanas{{ $doctorId ? ' con este doctor' : '' }}. Escríbenos por WhatsApp y te buscamos un espacio.</p>
    </div>
@else
    <div class="stack">
        <h2>Día</h2>
        <div class="days" role="group" aria-label="Días con horarios libres">
            @foreach ($availableDays as $day)
                @php($d = CarbonImmutable::parse($day))
                <button type="button" class="day" aria-pressed="{{ $date === $day ? 'true' : 'false' }}" wire:click="selectDate('{{ $day }}')" aria-label="{{ Dates::day($d) }}">
                    <span>{{ $d->isToday() ? 'Hoy' : ($d->isTomorrow() ? 'Mañana' : ucfirst($d->translatedFormat('D'))) }}</span>
                    <strong>{{ $d->day }}</strong>
                    <span>{{ $d->translatedFormat('M') }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="stack" wire:key="times-{{ $date }}-{{ $doctorId }}">
        <h2>{{ Dates::day($date) }}</h2>
        @foreach ($groups as $period => $slots)
            <div class="stack">
                <p class="muted small">{{ $period }}</p>
                <div class="times">
                    @foreach ($slots as $slot)
                        <button type="button" class="time" aria-pressed="{{ in_array($date.' '.$slot['time'], $selectedTimes, true) ? 'true' : 'false' }}" wire:click="{{ $action }}('{{ $slot['time'] }}')">
                            {{ Dates::time($slot['time']) }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if (! $showAllTimes && $this->visibleSlots->count() < $this->daySlots->count())
            <button type="button" class="link-btn" wire:click="showMoreTimes">Ver más horarios</button>
        @endif
    </div>
@endif
