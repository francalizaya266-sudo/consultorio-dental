<?php

namespace App\Filament\Forms;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
            fn (WeeklySchedule $component): Action => $component->getSeasonAction(),
        ]);
    }

    /**
     * El calendario de cada tramo: le pone las fechas entre las que vale (horario de verano). La agenda, la web y
     * los reportes ya respetaban esas fechas, pero ninguna pantalla dejaba ponerlas.
     */
    public function getSeasonAction(): Action
    {
        return Action::make('season')
            ->label('Solo por una temporada')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->iconButton()
            ->color(fn (array $arguments, WeeklySchedule $component) => $component->seasonOf((string) ($arguments['item'] ?? '')) === null ? 'gray' : 'primary')
            ->size(Size::Small)
            ->tooltip('Solo por una temporada')
            ->modalHeading('Tramo solo por una temporada')
            ->modalDescription('Para un horario que vale solo unas fechas, como el de verano: fuera de ellas, este tramo no cuenta. Deja las dos fechas vacías para que valga todo el año.')
            ->modalSubmitActionLabel('Listo')
            ->modalWidth('md')
            ->fillForm(fn (array $arguments, WeeklySchedule $component) => [
                'valid_from' => self::dateOnly($component->getRawItemState((string) $arguments['item'])['valid_from'] ?? null),
                'valid_until' => self::dateOnly($component->getRawItemState((string) $arguments['item'])['valid_until'] ?? null),
            ])
            ->schema([
                DatePicker::make('valid_from')
                    ->label('Vale desde')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->firstDayOfWeek(1)
                    ->closeOnDateSelection(),
                DatePicker::make('valid_until')
                    ->label('Vale hasta')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->firstDayOfWeek(1)
                    ->closeOnDateSelection()
                    ->afterOrEqual('valid_from')
                    ->validationMessages(['after_or_equal' => 'Tiene que ser el mismo día o después de "Vale desde".']),
            ])
            ->action(function (array $arguments, array $data, WeeklySchedule $component): void {
                $items = $component->getRawState() ?? [];
                $key = (string) ($arguments['item'] ?? '');

                if (! isset($items[$key])) {
                    return;
                }

                $items[$key]['valid_from'] = self::dateOnly($data['valid_from'] ?? null);
                $items[$key]['valid_until'] = self::dateOnly($data['valid_until'] ?? null);

                $component->rawState($items);
                $component->callAfterStateUpdated();
            });
    }

    /** "Solo del 15/12/2026 al 15/03/2027" si el tramo vale por una temporada; null si vale todo el año. */
    public function seasonOf(string $itemKey): ?string
    {
        $item = $this->getRawState()[$itemKey] ?? [];

        return self::seasonInWords($item['valid_from'] ?? null, $item['valid_until'] ?? null);
    }

    /** Las fechas entre las que vale un tramo, dichas en palabras; null si vale todo el año. */
    public static function seasonInWords(mixed $validFrom, mixed $validUntil): ?string
    {
        $day = fn (?string $date): string => CarbonImmutable::parse($date)->format('d/m/Y');
        [$from, $until] = [self::dateOnly($validFrom), self::dateOnly($validUntil)];

        return match (true) {
            $from !== null && $until !== null => 'Solo del '.$day($from).' al '.$day($until),
            $from !== null => 'Solo desde el '.$day($from),
            $until !== null => 'Solo hasta el '.$day($until),
            default => null,
        };
    }

    /** La fecha sola ("2026-12-15"), venga como la guarda la base, como la escribe el selector o vacía. */
    public static function dateOnly(mixed $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        return CarbonImmutable::parse((string) $date)->setTimezone(config('app.timezone'))->toDateString();
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
            'season' => $this->getAction('season'),
            'seasons' => collect(array_keys($this->getItems()))->mapWithKeys(fn (int|string $key) => [(string) $key => $this->seasonOf((string) $key)])->filter()->all(),
            'delete' => $this->getAction($this->getDeleteActionName()),
            'extraItemActions' => $this->getExtraItemActions(),
            'isDisabled' => $this->isDisabled(),
        ])->render();

        return $this->wrapEmbeddedHtml($html, labelTag: 'div');
    }
}
