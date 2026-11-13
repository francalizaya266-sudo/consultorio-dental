<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Field;
use Illuminate\Validation\Rule;

/**
 * Un color que se elige entre unos pocos botones con nombre, en vez de escribir un código como "#0ea5e9".
 * Si la ficha ya tenía un color fuera de la lista, se conserva como una opción más.
 */
class ColorSwatches extends Field
{
    protected string $view = 'filament.forms.color-swatches';

    /** @var array<string, string> Código del color => nombre con que se lee. */
    protected array $swatches = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(fn (ColorSwatches $component, mixed $state) => $component->state(filled($state) ? strtolower((string) $state) : $state));
        $this->rule(fn (ColorSwatches $component) => Rule::in(array_keys($component->getSwatches())));
    }

    /** @param  array<string, string>  $swatches */
    public function swatches(array $swatches): static
    {
        $this->swatches = $swatches;

        return $this;
    }

    /** @return array<string, string> */
    public function getSwatches(): array
    {
        $saved = strtolower((string) $this->getRecord()?->getAttribute($this->getName()));

        return $this->swatches + ($saved !== '' && ! isset($this->swatches[$saved]) ? [$saved => 'El que tenía'] : []);
    }
}
