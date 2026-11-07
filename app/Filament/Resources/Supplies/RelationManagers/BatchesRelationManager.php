<?php

namespace App\Filament\Resources\Supplies\RelationManagers;

use App\Filament\Resources\Supplies\SupplyActions;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\Supply;
use App\Models\SupplyBatch;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Los lotes del material, primero lo que vence antes. Lo que ya se terminó, aparte. */
class BatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'batches';

    protected static ?string $title = 'Lotes';

    protected static ?string $modelLabel = 'lote';

    public function table(Table $table): Table
    {
        /** @var Supply $supply */
        $supply = $this->getOwnerRecord();

        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supply')->orderByRaw('expires_on is null')->orderBy('expires_on')->orderBy('received_at'))
            ->columns([
                TextColumn::make('lot')
                    ->label('Lote')
                    ->description(fn (SupplyBatch $record) => $record->serial ? 'Serie '.$record->serial : null)
                    ->placeholder('Sin lote')
                    ->searchable(['lot', 'serial'])
                    ->weight('bold'),
                TextColumn::make('expires_on')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->color(fn ($state) => SupplyResource::expiryColor($state))
                    ->description(fn (SupplyBatch $record) => $record->isExpired() ? 'Vencido' : null)
                    ->placeholder('—'),
                TextColumn::make('remaining')
                    ->label('Queda')
                    ->formatStateUsing(fn (SupplyBatch $record) => $supply->quantity($record->remaining))
                    ->description(fn (SupplyBatch $record) => 'De '.$supply->quantity($record->quantity)),
                TextColumn::make('received_at')
                    ->label('Entró')
                    ->date('d/m/Y'),
                TextColumn::make('unit_cost')
                    ->label('Costo')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->placeholder('—')
                    ->visible(fn () => auth()->user()->can('viewCosts', Supply::class)),
            ])
            ->filters([
                TernaryFilter::make('available')
                    ->label('Lotes')
                    ->placeholder('Todos')
                    ->trueLabel('Con algo todavía')
                    ->falseLabel('Terminados')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('remaining', '>', 0),
                        false: fn (Builder $query) => $query->where('remaining', '<=', 0),
                    ),
            ])
            // "Dar de baja" va en el menú ⋮ de cada lote: rojo en cada fila parecía una alarma.
            ->recordActions([
                ActionGroup::make([SupplyActions::writeOff()]),
            ])
            ->emptyStateHeading('No queda ningún lote')
            ->emptyStateDescription('Cuando llegue una compra, regístrala con "Entrada" o desde Compras.');
    }
}
