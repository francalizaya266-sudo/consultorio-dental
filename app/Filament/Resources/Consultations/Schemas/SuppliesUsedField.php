<?php

namespace App\Filament\Resources\Consultations\Schemas;

use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\Supply;
use App\Services\Inventory\InventoryService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Al terminar la atención: lo que sale del inventario, ya llenado con la receta de lo trabajado.
 * El doctor borra lo que no usó y agrega lo que usó de más; los implantes se eligen por su serie.
 */
final class SuppliesUsedField
{
    public static function make(): Repeater
    {
        return Repeater::make('supplies_used')
            ->label('Materiales que usaste')
            // La columna de la serie aparece solo cuando hay un implante en la lista.
            ->table(fn (?array $state) => array_filter([
                TableColumn::make('Material'),
                TableColumn::make('Cantidad')->width('10rem'),
                collect($state ?? [])->contains(fn (array $line) => self::tracksSerial($line['supply_id'] ?? null))
                    ? TableColumn::make('Serie del implante')->width('16rem')
                    : null,
            ]))
            ->schema([
                Select::make('supply_id')
                    ->label('Material')
                    ->options(fn () => SupplyResource::pickerOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $set('batch_id', null);

                        if (self::tracksSerial($get('supply_id'))) {
                            $set('quantity', 1);
                        }
                    }),
                TextInput::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->minValue(0.01)
                    ->default(1)
                    ->required()
                    ->readOnly(fn (Get $get) => self::tracksSerial($get('supply_id')))
                    ->live(onBlur: true)
                    ->suffix(fn (Get $get) => Supply::unitFor($get('supply_id'), $get('quantity'))),
                Select::make('batch_id')
                    ->label('Serie del implante')
                    ->placeholder('Elige la serie')
                    ->options(fn (Get $get) => self::units($get('supply_id')))
                    ->visible(fn (Get $get) => self::tracksSerial($get('supply_id')))
                    // Sin unidades cargadas no hay serie que elegir: sale igual y queda por cargar.
                    ->required(fn (Get $get) => self::units($get('supply_id')) !== [])
                    ->distinct(),
            ])
            ->defaultItems(0)
            ->reorderable(false)
            ->addActionLabel('Agregar material');
    }

    private static function tracksSerial(mixed $supplyId): bool
    {
        return filled($supplyId) && (bool) Supply::query()->whereKey($supplyId)->value('tracks_serial');
    }

    /** @return array<int, string> */
    private static function units(mixed $supplyId): array
    {
        $supply = filled($supplyId) ? Supply::query()->find($supplyId) : null;

        return $supply?->tracks_serial ? app(InventoryService::class)->availableUnits($supply) : [];
    }
}
