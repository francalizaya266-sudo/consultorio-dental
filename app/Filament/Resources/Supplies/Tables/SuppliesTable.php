<?php

namespace App\Filament\Resources\Supplies\Tables;

use App\Enums\SupplyCategory;
use App\Filament\Resources\Supplies\SupplyActions;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\Supply;
use App\Support\Money;
use App\Support\Quantity;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Cada material con lo que hay (en rojo si falta), lo que vence primero y a quién se le pide. */
class SuppliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort('name')
            ->recordUrl(fn (Supply $record) => SupplyResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Material')
                    ->description(fn (Supply $record) => $record->category->getLabel().($record->tracks_serial ? ' · con número de serie' : ''))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('stock')
                    ->label('Hay')
                    ->formatStateUsing(fn (Supply $record) => $record->quantity($record->stock))
                    ->description(fn (Supply $record) => match (true) {
                        (float) $record->on_order > 0 => 'Pedido: llegan '.$record->quantity($record->on_order),
                        (float) $record->min_stock > 0 => 'Avisa con menos de '.Quantity::number($record->min_stock),
                        default => null,
                    })
                    ->color(fn (Supply $record) => $record->isLow() ? 'danger' : null)
                    ->weight(fn (Supply $record) => $record->isLow() ? 'bold' : null)
                    ->sortable(),
                TextColumn::make('next_expiry')
                    ->label('Vence primero')
                    ->date('d/m/Y')
                    ->color(fn ($state) => SupplyResource::expiryColor($state))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Proveedor')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit_cost')
                    ->label('Costo')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->placeholder('—')
                    ->visible(fn () => auth()->user()->can('viewCosts', Supply::class))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoría')
                    ->options(SupplyCategory::class),
                SelectFilter::make('supplier_id')
                    ->label('Proveedor')
                    ->relationship('supplier', 'name'),
            ])
            ->recordActions([
                SupplyActions::receive(),
                ViewAction::make()->label('Ver lotes')->color('gray'),
                EditAction::make()->color('gray'),
            ])
            ->emptyStateHeading('Todavía no hay materiales')
            ->emptyStateDescription('Empieza con la lista sugerida (anestesia, guantes, resinas…) y ajústala, o agrega tus materiales uno por uno.');
    }
}
