{{-- Botones de color con su nombre: se elige uno, como en una lista de opciones. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field" label-tag="div">
    <div role="radiogroup" aria-labelledby="{{ $getId() }}-label" class="color-swatches">
        @foreach ($getSwatches() as $color => $name)
            <label class="color-swatch">
                <input
                    type="radio"
                    name="{{ $getId() }}"
                    value="{{ $color }}"
                    {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}"
                    @disabled($isDisabled())
                />
                <span class="color-swatch-dot" style="background-color: {{ $color }}"></span>
                <span class="color-swatch-name">{{ $name }}</span>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
