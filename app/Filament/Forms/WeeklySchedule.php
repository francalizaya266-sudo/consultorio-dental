<?php

namespace App\Filament\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * El horario de la semana en una fila por día: "Lunes 09:00–13:00 y 15:00–19:00". Guarda lo mismo que la lista
 * de tramos de antes (un tramo = día, desde y hasta), pero sin una tarjeta por tramo que repetía el día.
 */
class WeeklySchedule extends Repeater
{
    public const DAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /** Lo que se propone al agregar un tramo: el primero del día, la mañana; el segundo, la tarde. */
    private const PROPOSED = [['09:00', '13:00'], ['15:00', '19:00']];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerActions([
            fn (WeeklySchedule $component): Action => $component->getAddRangeAction(),
        ]);
    }

    /** "Agregar tramo" en la fila de un día: el tramo nace en ese día, con un horario propuesto para corregir. */
    public function getAddRangeAction(): Action
    {
        return Action::make('addRange')
            ->label('Agregar tramo')
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->link()
            ->size(Size::Small)
            ->action(function (array $arguments, WeeklySchedule $component): void {
                $weekday = (int) ($arguments['weekday'] ?? 0);

                if (! isset(self::DAYS[$weekday])) {
                    return;
                }

                $items = $component->getRawState() ?? [];
                $already = collect($items)->filter(fn (mixed $item) => is_array($item) && (int) ($item['weekday'] ?? 0) === $weekday)->count();
                [$from, $until] = array_map(self::asPicked(...), self::PROPOSED[$already] ?? [null, null]);

                $items[$component->generateUuid() ?? (string) Str::uuid()] = ['weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $until];

                $component->rawState($items);
                $component->callAfterStateUpdated();
            });
    }

    /**
     * La hora como la guarda el selector de hora del panel mientras se edita: con la fecha de hoy delante. Escrita
     * solo como "09:00", el tramo se agregaba con las casillas vacías aunque la hora propuesta sí se guardaba.
     */
    private static function asPicked(?string $time): ?string
    {
        return $time === null ? null : today()->setTimeFromTimeString($time)->format('Y-m-d H:i:s');
    }

    /**
     * Los tramos de cada día, en el orden en que están en la ficha.
     *
     * @param  array<Schema>  $items
     * @return array<int, array<string, Schema>>
     */
    private function itemsByDay(array $items): array
    {
        $byDay = array_fill_keys(array_keys(self::DAYS), []);

        foreach ($items as $key => $item) {
            $weekday = (int) ($this->getRawItemState((string) $key)['weekday'] ?? 0);

            // Un tramo sin día (no debería haberlo) se muestra el lunes para que se pueda corregir o quitar.
            $byDay[isset(self::DAYS[$weekday]) ? $weekday : 1][(string) $key] = $item;
        }

        return $byDay;
    }

    public function toEmbeddedHtml(): string
    {
        $html = view('filament.forms.weekly-schedule', [
            'id' => $this->getId(),
            'days' => self::DAYS,
            'itemsByDay' => $this->itemsByDay($this->getItems()),
            'addRange' => $this->getAction('addRange'),
            'delete' => $this->getAction($this->getDeleteActionName()),
            'extraItemActions' => $this->getExtraItemActions(),
            'isDisabled' => $this->isDisabled(),
        ])->render();

        return $this->wrapEmbeddedHtml($html, labelTag: 'div');
    }
}
