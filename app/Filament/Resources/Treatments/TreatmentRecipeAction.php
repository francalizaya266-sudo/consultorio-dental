<?php

namespace App\Filament\Resources\Treatments;

use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\Supply;
use App\Models\Treatment;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * La receta del tratamiento: qué materiales usa una atención y cuánto. Al terminar la atención
 * salen solos del inventario (el doctor puede corregirlo ese día).
 */
final class TreatmentRecipeAction
{
    public static function make(): Action
    {
        return Action::make('supplies')
            ->label('Materiales')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->visible(fn (Treatment $record) => auth()->user()->can('update', $record) && Supply::query()->active()->exists())
            ->modalHeading(fn (Treatment $record) => 'Materiales de '.$record->name)
            ->modalDescription('Lo que se usa en una atención. Al terminarla, sale solo del inventario; el doctor puede corregir lo que de verdad usó.')
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (Treatment $record) => [
                'supplies' => $record->supplies()->orderBy('name')->get()
                    ->map(fn (Supply $supply) => ['supply_id' => $supply->getKey(), 'quantity' => (float) $supply->pivot->quantity])
                    ->all(),
            ])
            ->schema([
                Repeater::make('supplies')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Material'),
                        TableColumn::make('Cantidad')->width('11rem'),
                    ])
                    ->schema([
                        Select::make('supply_id')
                            ->label('Material')
                            ->options(fn () => SupplyResource::pickerOptions())
                            ->searchable()
                            ->required()
                            ->distinct()
                            ->live(),
                        TextInput::make('quantity')
                            ->label('Cantidad')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->live(onBlur: true)
                            ->suffix(fn (Get $get) => Supply::unitFor($get('supply_id'), $get('quantity'))),
                    ])
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel('Agregar material'),
                Text::make(fn (Get $get) => 'En materiales cuesta '.Money::format(self::cost($get('supplies') ?? [])).' por atención, con el último costo de cada uno.')
                    ->visible(fn () => auth()->user()->can('viewCosts', Supply::class)),
            ])
            ->action(function (array $data, Treatment $record) {
                $record->supplies()->sync(collect($data['supplies'] ?? [])
                    ->filter(fn (array $line) => filled($line['supply_id'] ?? null) && (float) ($line['quantity'] ?? 0) > 0)
                    ->mapWithKeys(fn (array $line) => [(int) $line['supply_id'] => ['quantity' => round((float) $line['quantity'], 2)]])
                    ->all());

                Notification::make()->success()->title('Materiales guardados.')->send();
            });
    }

    /** @param  array<array-key, array{supply_id?: mixed, quantity?: mixed}>  $lines */
    private static function cost(array $lines): float
    {
        $costs = Supply::query()->whereKey(collect($lines)->pluck('supply_id')->filter()->all())->pluck('unit_cost', 'id');

        return round(collect($lines)->sum(fn (array $line) => (float) ($line['quantity'] ?? 0) * (float) ($costs[(int) ($line['supply_id'] ?? 0)] ?? 0)), 2);
    }
}
