{{-- El horario de la semana, una fila por día. Cada tramo es un par de horas; las acciones van al final de la fila. --}}
<div id="{{ $id }}" role="group" aria-labelledby="{{ $id }}-label" class="week-schedule">
    @foreach ($days as $number => $name)
        @php($ranges = $itemsByDay[$number] ?? [])

        <div class="week-day" wire:key="{{ $id }}.day.{{ $number }}">
            <div class="week-day-name">{{ $name }}</div>

            <div class="week-day-ranges">
                @forelse ($ranges as $itemKey => $item)
                    <div class="week-range" wire:key="{{ $item->getLivewireKey() }}.item">
                        {!! $item->toHtml() !!}

                        @if (! $isDisabled)
                            {!! $season(['item' => $itemKey])->toHtml() !!}
                            {!! $delete(['item' => $itemKey])->toHtml() !!}
                        @endif

                        @isset($seasons[$itemKey])
                            <div class="week-range-season">{{ $seasons[$itemKey] }}</div>
                        @endisset
                    </div>
                @empty
                    <span class="week-day-closed">No atiende</span>
                @endforelse
            </div>

            @if (! $isDisabled)
                <div class="week-day-actions">
                    {!! $addRange(['weekday' => $number])->toHtml() !!}

                    @if ($ranges !== [])
                        @foreach ($extraItemActions as $extraItemAction)
                            {!! $extraItemAction(['item' => array_key_first($ranges)])->toHtml() !!}
                        @endforeach
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
